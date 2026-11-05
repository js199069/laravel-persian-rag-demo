# setup-week4-web.ps1 — دموی وب با استریم و ارجاع به منبع
$ErrorActionPreference = 'Stop'
$utf8NoBom = New-Object System.Text.UTF8Encoding $false

function Write-ProjectFile([string]$Path, [string]$Content) {
    $full = Join-Path (Get-Location) $Path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [System.IO.File]::WriteAllText($full, $Content, $utf8NoBom)
    Write-Host "ساخته شد: $Path" -ForegroundColor Green
}

if (-not (Test-Path 'artisan')) {
    Write-Host 'این اسکریپت باید در ریشه‌ی پروژه‌ی لاراول اجرا شود.' -ForegroundColor Red
    exit 1
}

# ---------- AnswerGenerator (با متد مشترک prepare) ----------
Write-ProjectFile 'app/Rag/Answering/AnswerGenerator.php' @'
<?php

namespace App\Rag\Answering;

use App\Rag\Search\HybridSearch;
use App\Rag\Support\PersianNormalizer;

final class AnswerGenerator
{
    public function __construct(
        private HybridSearch $search,          // embedding همیشه با مدل محلیِ ایندکس
        private string $llmProvider = 'ollama_local',
        private int $k = 3,
        private float $minSimilarity = 0.55,
        private int $timeout = 300,
    ) {}

    /**
     * جست‌وجو + ساختن منبع‌ها و پرامپت؛ مشترک بین خط فرمان و وب.
     *
     * @param  string[]|null  $audiences
     */
    public function prepare(string $question, ?array $audiences = null): array
    {
        $refusal = config('rag.answer.refusal', 'پاسخ این سؤال در اسناد موجود نیامده است.');

        $start = microtime(true);
        $found = $this->search->search($question, $audiences, $this->k);

        $prepared = [
            'refusal' => $refusal,
            'best_similarity' => $found['best_similarity'],
            'search_ms' => (int) ((microtime(true) - $start) * 1000),
            'sources' => [],
            'instructions' => '',
            'prompt' => '',
            'refused' => false,
        ];

        // لایه‌ی ۱: سؤال کاملاً بی‌ربط
        if ($found['results'] === [] || $found['best_similarity'] < $this->minSimilarity) {
            $prepared['refused'] = true;

            return $prepared;
        }

        $context = '';
        foreach ($found['results'] as $i => $hit) {
            $n = $i + 1;
            $chunk = $hit['chunk'];
            $pages = $chunk->page_from === $chunk->page_to
                ? "صفحه‌ی {$chunk->page_from}"
                : "صفحه‌های {$chunk->page_from} تا {$chunk->page_to}";

            $prepared['sources'][$n] = [
                'doc_key' => $chunk->document->doc_key,
                'title' => $chunk->document->title,
                'audience' => $chunk->document->audience,
                'pages' => $pages,
                'snippet' => mb_substr(preg_replace('/\s+/u', ' ', $chunk->content), 0, 350),
            ];
            $context .= "[{$n}] {$chunk->document->title}، {$pages}\n{$chunk->content}\n\n";
        }

        $prepared['instructions'] = implode("\n", [
            'تو فقط از روی منبع‌های داده‌شده به سؤال جواب می‌دهی.',
            'مرحله ۱: در منبع‌ها دنبال جمله‌ای بگرد که مستقیماً به سؤال جواب می‌دهد.',
            'مرحله ۲: اگر پیدا کردی، جواب را در یک یا دو جمله‌ی کوتاه بنویس و شماره‌ی منبع را آخرش بگذار، مثل [1].',
            "مرحله ۳: اگر هیچ منبعی جواب سؤال را ندارد، فقط بنویس: {$refusal}",
            'هرگز جواب و جمله‌ی «نیامده» را با هم ننویس. فقط به فارسی جواب بده.',
        ]);
        $prepared['prompt'] = "سؤال: {$question}\n\nمنبع‌ها:\n{$context}سؤال: {$question}\nپاسخ:";

        return $prepared;
    }

    /** @param  string[]|null  $audiences */
    public function answer(string $question, ?array $audiences = null): array
    {
        $p = $this->prepare($question, $audiences);

        $result = [
            'best_similarity' => $p['best_similarity'],
            'search_ms' => $p['search_ms'],
            'llm_ms' => 0,
            'llm_provider' => $this->llmProvider,
            'sources' => [],
            'cited' => [],
            'invalid_citations' => [],
            'mixed' => false,
        ];

        if ($p['refused']) {
            return $result + ['answer' => $p['refusal'], 'refused' => true, 'refused_by' => 'retrieval'];
        }

        $llmStart = microtime(true);
        $text = trim((string) (new RagAnswerAgent($p['instructions']))->prompt(
            $p['prompt'],
            provider: $this->llmProvider,
            timeout: $this->timeout,
        ));
        $result['llm_ms'] = (int) ((microtime(true) - $llmStart) * 1000);

        $parsed = PersianNormalizer::normalize($text);
        preg_match_all('/\[(\d+)\]/', $parsed, $matches);
        $cited = array_values(array_unique(array_map('intval', $matches[1])));

        $result['sources'] = $p['sources'];
        $result['cited'] = $cited;
        $result['invalid_citations'] = array_values(array_diff($cited, array_keys($p['sources'])));

        // سه حالت: جواب، امتناع خالص، مختلط
        $mentionsRefusal = str_contains($parsed, 'نیامده');
        $rest = preg_replace('/\[\d+\]/u', '', str_replace(PersianNormalizer::normalize($p['refusal']), '', $parsed));
        $rest = preg_replace('/[\s.،,:؛]+/u', '', $rest);
        $pureRefusal = $mentionsRefusal && mb_strlen($rest) < 15;

        $result['mixed'] = $mentionsRefusal && ! $pureRefusal;

        return $result + ['answer' => $text, 'refused' => $pureRefusal, 'refused_by' => $pureRefusal ? 'llm' : null];
    }
}
'@

# ---------- Controller ----------
Write-ProjectFile 'app/Http/Controllers/RagDemoController.php' @'
<?php

namespace App\Http\Controllers;

use App\Rag\Answering\AnswerGenerator;
use App\Rag\Answering\RagAnswerAgent;
use App\Rag\Search\HybridSearch;
use Illuminate\Http\Request;

class RagDemoController extends Controller
{
    private const ROLE_LABELS = [
        'bachelor' => 'دانشجوی کارشناسی',
        'phd' => 'دانشجوی دکتری',
        'faculty' => 'عضو هیئت علمی',
        'staff' => 'کارمند آموزش',
    ];

    private const EXAMPLES = [
        ['label' => 'مرجع رسیدگی به تخلفات دانشجویان', 'q' => 'مرجع رسیدگی به تخلفات انضباطی دانشجویان کیست؟', 'role' => 'bachelor'],
        ['label' => 'یک واحد نظری چند جلسه است؟', 'q' => 'یک واحد درس نظری چند جلسه آموزش است؟', 'role' => 'bachelor'],
        ['label' => 'آزمون جامع دکتری', 'q' => 'آزمون جامع دکتری در سال چند بار برگزار می‌شود؟', 'role' => 'phd'],
        ['label' => 'تخلف مواد مخدر (هیئت علمی)', 'q' => 'تهیه یا مصرف مواد مخدر توسط عضو هیئت علمی چه نوع تخلفی است؟', 'role' => 'faculty'],
        ['label' => 'همان سؤال، با نقش دانشجو', 'q' => 'تهیه یا مصرف مواد مخدر توسط عضو هیئت علمی چه نوع تخلفی است؟', 'role' => 'bachelor'],
        ['label' => 'سؤالی که جوابش در اسناد نیست', 'q' => 'حقوق اعضای هیئت علمی چه روزی از ماه پرداخت می‌شود؟', 'role' => 'faculty'],
    ];

    public function index()
    {
        return view('rag-demo', [
            'roles' => array_intersect_key(self::ROLE_LABELS, config('rag.roles', [])),
            'examples' => self::EXAMPLES,
            'model' => config('rag.answer.provider', 'ollama_local'),
        ]);
    }

    /** مرحله‌ی ۱: جست‌وجو؛ منبع‌ها فوراً به مرورگر می‌روند */
    public function search(Request $request)
    {
        $prepared = $this->generator()->prepare($this->question($request), $this->audiences($request));

        return response()->json([
            'refused' => $prepared['refused'],
            'refusal' => $prepared['refusal'],
            'best_similarity' => round($prepared['best_similarity'], 3),
            'search_ms' => $prepared['search_ms'],
            'model' => config('rag.answer.provider', 'ollama_local'),
            'sources' => collect($prepared['sources'])->map(fn ($s, $n) => $s + ['n' => $n])->values(),
        ]);
    }

    /** مرحله‌ی ۲: جواب، به‌صورت استریم */
    public function answer(Request $request)
    {
        $prepared = $this->generator()->prepare($this->question($request), $this->audiences($request));
        abort_if($prepared['refused'], 422, 'سند مرتبطی پیدا نشد');

        return (new RagAnswerAgent($prepared['instructions']))
            ->stream($prepared['prompt'], provider: config('rag.answer.provider', 'ollama_local'))
            ->usingVercelDataProtocol();
    }

    private function generator(): AnswerGenerator
    {
        return new AnswerGenerator(
            new HybridSearch('ollama_local'),
            config('rag.answer.provider', 'ollama_local'),
            (int) config('rag.answer.k', 3),
            (float) config('rag.min_similarity', 0.55),
        );
    }

    private function question(Request $request): string
    {
        $question = trim((string) $request->query('q', ''));
        abort_if($question === '' || mb_strlen($question) > 500, 422, 'سؤال نامعتبر');

        return $question;
    }

    /** در دمو نقش از مرورگر می‌آید؛ در نرم‌افزار واقعی باید از کاربر واردشده خوانده شود */
    private function audiences(Request $request): ?array
    {
        $role = (string) $request->query('role', '');
        if ($role === '') {
            return null;
        }

        $audiences = config("rag.roles.{$role}");
        abort_if($audiences === null, 422, 'نقش نامعتبر');

        return $audiences;
    }
}
'@

# ---------- View ----------
Write-ProjectFile 'resources/views/rag-demo.blade.php' @'
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>دستیار اسناد آموزشی — دمو</title>
<style>
  :root { --bg:#f6f7f9; --card:#fff; --text:#1f2937; --muted:#6b7280; --accent:#2563eb; --border:#e5e7eb; --warn:#b45309; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Tahoma, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); line-height: 1.9; }
  .wrap { max-width: 920px; margin: 0 auto; padding: 24px 16px 64px; }
  h1 { font-size: 1.35rem; margin: 0 0 4px; }
  .sub { color: var(--muted); margin: 0 0 20px; font-size: .9rem; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
  .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
  label { font-size: .9rem; color: var(--muted); }
  select, textarea, button { font: inherit; }
  select { padding: 6px 10px; border: 1px solid var(--border); border-radius: 8px; background: #fff; }
  textarea { width: 100%; min-height: 72px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; resize: vertical; margin-top: 10px; }
  .primary { background: var(--accent); color: #fff; border: 0; border-radius: 10px; padding: 8px 22px; cursor: pointer; }
  .primary:disabled { opacity: .5; cursor: default; }
  .chips { display: flex; flex-wrap: wrap; gap: 8px; }
  .chip { background: #eef2ff; color: #3730a3; border: 0; border-radius: 999px; padding: 4px 12px; cursor: pointer; font-size: .82rem; }
  #answer { white-space: pre-wrap; min-height: 2em; font-size: 1.02rem; }
  #answer.refused { color: var(--warn); }
  .cite { color: var(--accent); text-decoration: none; font-size: .78em; vertical-align: super; padding: 0 1px; }
  .status { color: var(--muted); font-size: .8rem; margin-top: 10px; }
  .title { font-weight: bold; margin-bottom: 4px; }
  .source { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; margin-top: 10px; transition: background .3s; }
  .source.cited { border-color: var(--accent); background: #f5f8ff; }
  .source .head { font-size: .9rem; font-weight: bold; }
  .source .meta { font-size: .76rem; color: var(--muted); }
  .source .snippet { font-size: .84rem; color: #374151; margin-top: 6px; }
  .note { font-size: .76rem; color: var(--muted); margin: 10px 0 0; }
</style>
</head>
<body>
<div class="wrap">
  <h1>دستیار اسناد آموزشی</h1>
  <p class="sub">پاسخ به سؤال از روی آیین‌نامه‌ها و بخشنامه‌ها، با ارجاع به منبع و شماره‌ی صفحه. ایندکس و جست‌وجو کاملاً محلی؛ مدل پاسخ‌گو: <b>{{ $model }}</b></p>

  <div class="card">
    <form id="ask">
      <div class="row">
        <label for="role">پرسش به عنوان:</label>
        <select id="role">
          @foreach ($roles as $key => $label)
            <option value="{{ $key }}">{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <textarea id="q" placeholder="سؤالت را بنویس..."></textarea>
      <div class="row" style="justify-content: space-between; margin-top: 10px">
        <div class="chips">
          @foreach ($examples as $ex)
            <button type="button" class="chip" data-q="{{ $ex['q'] }}" data-role="{{ $ex['role'] }}">{{ $ex['label'] }}</button>
          @endforeach
        </div>
        <button class="primary" id="send" type="submit">بپرس</button>
      </div>
    </form>
    <p class="note">در این دمو نقش از فهرست انتخاب می‌شود؛ در نرم‌افزار واقعی، نقش از حساب کاربری واردشده خوانده می‌شود، نه از مرورگر.</p>
  </div>

  <div class="card" id="answerCard" hidden>
    <div class="title">پاسخ</div>
    <div id="answer"></div>
    <div class="status" id="status"></div>
  </div>

  <div class="card" id="sourcesCard" hidden>
    <div class="title">منبع‌ها</div>
    <div id="sources"></div>
  </div>
</div>

<script>
const $ = (id) => document.getElementById(id);
const toLatinDigits = (s) => s.replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let current = null;

function renderAnswer(text) {
  $('answer').innerHTML = escapeHtml(toLatinDigits(text))
    .replace(/\[(\d+)\]/g, '<a class="cite" href="#src-$1">[$1]</a>');
}

function renderSources(sources) {
  $('sources').innerHTML = sources.map((s) => `
    <div class="source" id="src-${s.n}">
      <div class="head">[${s.n}] ${escapeHtml(s.title)}</div>
      <div class="meta">${escapeHtml(s.doc_key)} · ${escapeHtml(s.pages)} · مخاطب: ${escapeHtml(s.audience)}</div>
      <div class="snippet">${escapeHtml(s.snippet)}…</div>
    </div>`).join('');
  $('sourcesCard').hidden = sources.length === 0;
}

function markCited(text) {
  const cited = new Set([...toLatinDigits(text).matchAll(/\[(\d+)\]/g)].map((m) => m[1]));
  document.querySelectorAll('.source').forEach((el) => {
    el.classList.toggle('cited', cited.has(el.id.replace('src-', '')));
  });
}

async function ask(q, role) {
  if (current) current.close();
  $('send').disabled = true;
  $('answerCard').hidden = false;
  $('answer').className = '';
  $('answer').textContent = '';
  renderSources([]);
  $('status').textContent = 'در حال جست‌وجو در اسناد...';

  const t0 = performance.now();
  const params = new URLSearchParams({ q, role });

  let data;
  try {
    const res = await fetch('/rag/search?' + params);
    if (!res.ok) throw new Error(res.status);
    data = await res.json();
  } catch (e) {
    $('status').textContent = 'خطا در جست‌وجو (' + e.message + ').';
    $('send').disabled = false;
    return;
  }

  renderSources(data.sources);
  const info = `شباهت: ${data.best_similarity} · جست‌وجو: ${data.search_ms}ms`;

  if (data.refused) {
    $('answer').className = 'refused';
    $('answer').textContent = data.refusal;
    $('status').textContent = info + ' · امتناع در مرحله‌ی جست‌وجو: سند مرتبطی پیدا نشد';
    $('send').disabled = false;
    return;
  }

  $('status').textContent = info + ' · در حال نوشتن پاسخ...';
  let text = '';
  const es = new EventSource('/rag/answer?' + params);
  current = es;

  const finish = () => {
    es.close();
    current = null;
    $('send').disabled = false;
    if (!text) {
      $('answer').textContent = 'پاسخی دریافت نشد. خروجی خام را در این آدرس ببین: /rag/answer?' + params;
    }
    if (text.includes('نیامده')) $('answer').className = 'refused';
    markCited(text);
    const total = ((performance.now() - t0) / 1000).toFixed(1);
    $('status').textContent = `${info} · کل زمان: ${total} ثانیه · مدل: ${data.model}`;
  };

  es.onmessage = (e) => {
    if (e.data === '[DONE]') { finish(); return; }
    let part;
    try { part = JSON.parse(e.data); } catch { return; }
    const type = String(part.type || '');
    const delta = typeof part.delta === 'string' ? part.delta
      : (typeof part.textDelta === 'string' ? part.textDelta : null);
    if (delta !== null && type.includes('text')) {
      text += delta;
      renderAnswer(text);
    }
    if (type === 'error') {
      text += '\n[خطا: ' + (part.errorText || 'نامشخص') + ']';
      renderAnswer(text);
      finish();
    }
  };
  es.onerror = finish;
}

$('ask').addEventListener('submit', (e) => {
  e.preventDefault();
  const q = $('q').value.trim();
  if (q) ask(q, $('role').value);
});

document.querySelectorAll('.chip').forEach((b) => b.addEventListener('click', () => {
  $('q').value = b.dataset.q;
  $('role').value = b.dataset.role;
  ask(b.dataset.q, b.dataset.role);
}));
</script>
</body>
</html>
'@

# ---------- Routes ----------
Write-ProjectFile 'routes/web.php' @'
<?php

use App\Http\Controllers\RagDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RagDemoController::class, 'index']);
Route::get('/rag/search', [RagDemoController::class, 'search']);
Route::get('/rag/answer', [RagDemoController::class, 'answer']);
'@

Write-Host ''
Write-Host 'تمام شد. قدم بعد:' -ForegroundColor Cyan
Write-Host '  php artisan serve'
Write-Host '  مرورگر: http://127.0.0.1:8000'