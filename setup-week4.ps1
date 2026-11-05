# setup-week4.ps1 — تولید پاسخ با ارجاع + ارزیابی در سطح پاسخ
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

# ---------- config/rag.php ----------
Write-ProjectFile 'config/rag.php' @'
<?php

return [
    // هر نقش کاربر، اسناد کدام گروه‌های مخاطب را می‌تواند ببیند
    'roles' => [
        'bachelor' => ['bachelor', 'all_students'],
        'phd' => ['phd', 'all_students'],
        'faculty' => ['faculty', 'phd', 'bachelor', 'all_students'],
        'staff' => ['staff', 'phd', 'bachelor', 'all_students'],
    ],

    // لایه‌ی ۱ «جواب نده»: زیر این شباهت، مدل زبانی صدا زده نمی‌شود (از ارزیابی هفته‌ی ۳)
    'min_similarity' => 0.55,

    'answer' => [
        'k' => 4,
        'refusal' => 'پاسخ این سؤال در اسناد موجود نیامده است.',
    ],
];
'@

# ---------- AnswerGenerator ----------
Write-ProjectFile 'app/Rag/Answering/AnswerGenerator.php' @'
<?php

namespace App\Rag\Answering;

use App\Rag\Search\HybridSearch;
use App\Rag\Support\PersianNormalizer;

use function Laravel\Ai\agent;

final class AnswerGenerator
{
    public function __construct(
        private HybridSearch $search,
        private string $provider = 'ollama_local',
        private int $k = 4,
        private float $minSimilarity = 0.55,
        private int $timeout = 300,
    ) {}

    /** @param  string[]|null  $audiences */
    public function answer(string $question, ?array $audiences = null): array
    {
        $refusal = config('rag.answer.refusal', 'پاسخ این سؤال در اسناد موجود نیامده است.');

        $start = microtime(true);
        $found = $this->search->search($question, $audiences, $this->k);
        $searchMs = (int) ((microtime(true) - $start) * 1000);

        $result = [
            'best_similarity' => $found['best_similarity'],
            'search_ms' => $searchMs,
            'llm_ms' => 0,
            'sources' => [],
            'cited' => [],
            'invalid_citations' => [],
        ];

        // لایه‌ی ۱: سؤال کاملاً بی‌ربط؛ مدل اصلاً صدا زده نمی‌شود
        if ($found['results'] === [] || $found['best_similarity'] < $this->minSimilarity) {
            return $result + ['answer' => $refusal, 'refused' => true, 'refused_by' => 'retrieval'];
        }

        // منبع‌های شماره‌دار
        $sources = [];
        $context = '';
        foreach ($found['results'] as $i => $hit) {
            $n = $i + 1;
            $chunk = $hit['chunk'];
            $pages = $chunk->page_from === $chunk->page_to
                ? "صفحه‌ی {$chunk->page_from}"
                : "صفحه‌های {$chunk->page_from} تا {$chunk->page_to}";

            $sources[$n] = [
                'doc_key' => $chunk->document->doc_key,
                'title' => $chunk->document->title,
                'pages' => $pages,
            ];
            $context .= "[{$n}] {$chunk->document->title}، {$pages}\n{$chunk->content}\n\n";
        }

        $instructions = implode("\n", [
            'تو دستیار پاسخ‌گویی به سؤال‌ها بر اساس اسناد رسمی دانشگاه هستی.',
            '۱. فقط از اطلاعات منبع‌های شماره‌دار استفاده کن و چیزی از خودت اضافه نکن.',
            '۲. بعد از هر جمله‌ی پاسخ، شماره‌ی منبع آن را بیاور، مثل [1] یا [2].',
            "۳. اگر پاسخ در منبع‌ها نیست، فقط و دقیقاً بنویس: {$refusal}",
            '۴. کوتاه، دقیق و فقط به فارسی پاسخ بده.',
        ]);

        // لایه‌ی ۲: مدل زبانی
        $llmStart = microtime(true);
        $text = trim((string) agent(instructions: $instructions)->prompt(
            "منبع‌ها:\n{$context}سؤال: {$question}",
            provider: $this->provider,
            timeout: $this->timeout,
        ));
        $result['llm_ms'] = (int) ((microtime(true) - $llmStart) * 1000);

        // خواندن ارجاع‌ها (ارقام فارسی هم پذیرفته می‌شوند)
        $parsed = PersianNormalizer::normalize($text);
        preg_match_all('/\[(\d+)\]/', $parsed, $matches);
        $cited = array_values(array_unique(array_map('intval', $matches[1])));

        $result['sources'] = $sources;
        $result['cited'] = $cited;
        $result['invalid_citations'] = array_values(array_diff($cited, array_keys($sources)));

        $refused = str_contains($parsed, 'نیامده');

        return $result + ['answer' => $text, 'refused' => $refused, 'refused_by' => $refused ? 'llm' : null];
    }
}
'@

# ---------- AskCorpus ----------
Write-ProjectFile 'app/Console/Commands/AskCorpus.php' @'
<?php

namespace App\Console\Commands;

use App\Rag\Answering\AnswerGenerator;
use App\Rag\Search\HybridSearch;
use Illuminate\Console\Command;

class AskCorpus extends Command
{
    protected $signature = 'rag:ask
        {question? : سؤال (اگر فارسی درست نرسید، از --file استفاده کن)}
        {--file= : فایل UTF-8 با یک سؤال در هر خط}
        {--role= : نقش کاربر از config/rag.php}
        {--k=4 : تعداد تکه‌ی منبع}
        {--provider=ollama_local}';

    protected $description = 'پاسخ به سؤال بر اساس اسناد، با ارجاع به منبع';

    public function handle(): int
    {
        $questions = [];
        if ($this->option('file')) {
            $lines = preg_split('/\r\n|\n|\r/', file_get_contents($this->option('file')));
            $questions = array_values(array_filter(array_map(
                fn ($l) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $l)), $lines,
            )));
        } elseif ($this->argument('question')) {
            $questions = [$this->argument('question')];
        }

        if ($questions === []) {
            $this->error('یک سؤال بده، یا --file=مسیر-فایل');

            return self::FAILURE;
        }

        $audiences = null;
        if ($role = $this->option('role')) {
            $audiences = config("rag.roles.{$role}");
            if ($audiences === null) {
                $this->error("نقش «{$role}» در config/rag.php تعریف نشده.");

                return self::FAILURE;
            }
        }

        $provider = $this->option('provider');
        $generator = new AnswerGenerator(
            new HybridSearch($provider),
            $provider,
            (int) $this->option('k'),
            (float) config('rag.min_similarity', 0.55),
        );

        foreach ($questions as $question) {
            $this->newLine();
            $this->info("سؤال: {$question}" . ($audiences ? " [نقش: {$this->option('role')}]" : ''));

            $r = $generator->answer($question, $audiences);

            $this->line($r['answer']);
            $this->newLine();

            foreach ($r['sources'] as $n => $source) {
                $mark = in_array($n, $r['cited'], true) ? '✓' : ' ';
                $this->line("  {$mark} [{$n}] {$source['doc_key']} — {$source['title']}، {$source['pages']}");
            }

            if ($r['invalid_citations'] !== []) {
                $this->warn('ارجاع به منبع ناموجود: ' . implode('، ', $r['invalid_citations']));
            }

            $this->line(sprintf(
                '<comment>شباهت: %.3f | جست‌وجو: %dms | مدل: %dms%s</comment>',
                $r['best_similarity'], $r['search_ms'], $r['llm_ms'],
                $r['refused'] ? ' | امتناع توسط ' . ($r['refused_by'] === 'retrieval' ? 'جست‌وجو' : 'مدل') : '',
            ));
        }

        return self::SUCCESS;
    }
}
'@

# ---------- EvaluateAnswers ----------
Write-ProjectFile 'app/Console/Commands/EvaluateAnswers.php' @'
<?php

namespace App\Console\Commands;

use App\Rag\Answering\AnswerGenerator;
use App\Rag\Search\HybridSearch;
use App\Rag\Support\PersianNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class EvaluateAnswers extends Command
{
    protected $signature = 'rag:eval-answers
        {--file=storage/app/eval.csv : فایل سؤال‌های ارزیابی}
        {--ids= : فقط این شناسه‌ها، مثلاً 1,2,16}
        {--k=4 : تعداد تکه‌ی منبع}
        {--provider=ollama_local}';

    protected $description = 'ارزیابی در سطح پاسخ: درستی، امتناع در سؤال بی‌جواب، و اعتبار ارجاع‌ها';

    public function handle(): int
    {
        $file = $this->option('file');
        $path = is_file($file) ? $file : base_path($file);
        if (! is_file($path)) {
            $this->error("فایل ارزیابی پیدا نشد: {$path}");

            return self::FAILURE;
        }

        $ids = $this->option('ids') ? array_map('trim', explode(',', $this->option('ids'))) : null;
        $provider = $this->option('provider');
        $generator = new AnswerGenerator(
            new HybridSearch($provider),
            $provider,
            (int) $this->option('k'),
            (float) config('rag.min_similarity', 0.55),
        );

        $table = [];
        $details = [];
        $stats = [
            'answerable' => 0, 'false_refusals' => 0,
            'keyword_total' => 0, 'keyword_correct' => 0,
            'citation_total' => 0, 'citation_ok' => 0, 'invalid_citations' => 0,
            'negative' => 0, 'refused_retrieval' => 0, 'refused_llm' => 0,
            'access' => 0, 'leaks' => 0,
            'llm_ms' => [],
        ];

        foreach ($this->readCsv($path) as $row) {
            if ($ids !== null && ! in_array($row['id'], $ids, true)) {
                continue;
            }

            $role = trim($row['role'] ?? '');
            $audiences = $role !== '' ? config("rag.roles.{$role}") : null;
            $expectedDocs = array_values(array_filter(array_map('trim', explode('|', $row['expected_doc'] ?? ''))));
            $keywords = array_values(array_filter(array_map('trim', explode('|', $row['expected_answer'] ?? ''))));
            $mustNotDoc = trim($row['must_not_doc'] ?? '');

            $this->line("سؤال {$row['id']} ...");
            $r = $generator->answer($row['question'], $audiences);
            if ($r['llm_ms'] > 0) {
                $stats['llm_ms'][] = $r['llm_ms'];
            }
            $stats['invalid_citations'] += count($r['invalid_citations']);
            $sourceDocs = array_column($r['sources'], 'doc_key');
            $excerpt = mb_substr(preg_replace('/\s+/u', ' ', $r['answer']), 0, 70);
            $refusedLabel = $r['refused'] ? ($r['refused_by'] === 'retrieval' ? 'جست‌وجو' : 'مدل') : '-';

            if ($mustNotDoc !== '') {
                $stats['access']++;
                $leak = in_array($mustNotDoc, $sourceDocs, true);
                $stats['leaks'] += $leak ? 1 : 0;
                $table[] = [$row['id'], 'access', $refusedLabel, '-', $leak ? '❌ نشت' : '✅', $excerpt];
            } elseif ($expectedDocs === []) {
                $stats['negative']++;
                if ($r['refused']) {
                    $stats[$r['refused_by'] === 'retrieval' ? 'refused_retrieval' : 'refused_llm']++;
                }
                $table[] = [$row['id'], 'negative', $r['refused'] ? "✅ {$refusedLabel}" : '❌ جواب داد', '-', '-', $excerpt];
            } else {
                $stats['answerable']++;
                $stats['false_refusals'] += $r['refused'] ? 1 : 0;

                $correct = '-';
                if ($keywords !== []) {
                    $stats['keyword_total']++;
                    $answerForm = PersianNormalizer::searchForm($r['answer']);
                    $hit = ! $r['refused'] && collect($keywords)->contains(
                        fn ($k) => str_contains($answerForm, PersianNormalizer::searchForm($k)),
                    );
                    $stats['keyword_correct'] += $hit ? 1 : 0;
                    $correct = $hit ? '✅' : '❌';
                }

                $citation = '-';
                if (! $r['refused']) {
                    $stats['citation_total']++;
                    $citedDocs = array_map(fn ($n) => $r['sources'][$n]['doc_key'] ?? null, $r['cited']);
                    $ok = array_intersect($citedDocs, $expectedDocs) !== [];
                    $stats['citation_ok'] += $ok ? 1 : 0;
                    $citation = $ok ? '✅' : '❌';
                }

                $table[] = [$row['id'], 'answer', $r['refused'] ? "❌ امتناع ({$refusedLabel})" : '✅', $correct, $citation, $excerpt];
            }

            $details[] = ['id' => $row['id'], 'question' => $row['question'], 'role' => $role] + $r;
        }

        $this->table(['id', 'type', 'answered/refused', 'keyword', 'citation', 'answer'], $table);

        if ($stats['answerable'] > 0) {
            $this->info(sprintf(
                'جواب‌دار (%d): امتناع اشتباه %d | کلمه‌ی کلیدی درست %d از %d | ارجاع به سند درست %d از %d',
                $stats['answerable'], $stats['false_refusals'],
                $stats['keyword_correct'], $stats['keyword_total'],
                $stats['citation_ok'], $stats['citation_total'],
            ));
        }
        if ($stats['negative'] > 0) {
            $this->info(sprintf(
                'بی‌جواب (%d): امتناع درست %d (جست‌وجو %d، مدل %d)',
                $stats['negative'], $stats['refused_retrieval'] + $stats['refused_llm'],
                $stats['refused_retrieval'], $stats['refused_llm'],
            ));
        }
        if ($stats['access'] > 0) {
            $this->info("دسترسی ({$stats['access']}): {$stats['leaks']} نشت");
        }
        $this->info("ارجاع به منبع ناموجود: {$stats['invalid_citations']}");
        if ($stats['llm_ms'] !== []) {
            $this->info(sprintf('میانگین زمان مدل: %d ثانیه', array_sum($stats['llm_ms']) / count($stats['llm_ms']) / 1000));
        }

        $out = 'eval-answers/' . now()->format('Ymd-His') . '.json';
        Storage::put($out, json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("پاسخ‌های کامل ذخیره شد: storage/app/{$out}");

        return self::SUCCESS;
    }

    private function readCsv(string $path): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($path));
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $header = array_map('trim', str_getcsv(array_shift($lines), ',', '"', ''));

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = array_slice(array_pad(str_getcsv($line, ',', '"', ''), count($header), ''), 0, count($header));
            $rows[] = array_combine($header, $values);
        }

        return $rows;
    }
}
'@

Write-Host ''
Write-Host 'تمام شد. قدم‌های بعد:' -ForegroundColor Cyan
Write-Host '  php artisan config:clear'
Write-Host '  php artisan rag:ask --file=storage/app/questions.txt --role=faculty'