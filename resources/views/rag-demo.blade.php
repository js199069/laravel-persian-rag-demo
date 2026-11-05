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
    if (!res.ok) {
      // پیام سرور (مثلاً «Ollama روشن است؟») از کد خطا مفیدتر است
      let message = 'خطا در جست‌وجو (' + res.status + ').';
      try {
        const body = await res.json();
        if (body.error) message = body.error;
        else if (body.message) message = body.message;
      } catch (_) { /* پاسخ JSON نبود؛ همان کد خطا */ }
      throw new Error(message);
    }
    data = await res.json();
  } catch (e) {
    $('answer').className = 'refused';
    $('answer').textContent = e.message === 'Failed to fetch'
      ? 'اتصال به سرور برقرار نشد. php artisan serve در حال اجراست؟'
      : e.message;
    $('status').textContent = '';
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