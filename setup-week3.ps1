# setup-week3.ps1 — تکه‌تکه کردن، embedding و جست‌وجوی ترکیبی
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

# ---------- Migration ----------
Write-ProjectFile 'database/migrations/2026_11_04_000001_create_rag_tables.php' @'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // اکستنشن vector قبلاً با کاربر postgres ساخته شده (کاربر rag دسترسی ساختنش را ندارد)

        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_key')->unique();      // doc-01
            $table->string('title');
            $table->string('audience')->index();       // phd، bachelor، all_students، staff، faculty
            $table->string('file');
            $table->string('method')->nullable();      // best، docx، ...
            $table->unsignedInteger('pages')->default(0);
            $table->timestamps();
        });

        Schema::create('rag_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rag_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('page_from');
            $table->unsignedInteger('page_to');
            $table->text('content');                   // متن نرمال‌شده، برای نمایش و LLM
            $table->text('search_text');               // searchForm، برای جست‌وجوی متنی
            $table->string('embedding_model');
            $table->unsignedSmallInteger('embedding_dims');
            $table->vector('embedding', dimensions: 1024)->index();   // ایندکس HNSW با فاصله‌ی cosine
            $table->timestamps();
        });

        DB::statement("ALTER TABLE rag_chunks ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('simple', search_text)) STORED");
        DB::statement('CREATE INDEX rag_chunks_tsv_idx ON rag_chunks USING GIN (tsv)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_chunks');
        Schema::dropIfExists('rag_documents');
    }
};
'@

# ---------- Models ----------
Write-ProjectFile 'app/Models/RagDocument.php' @'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RagDocument extends Model
{
    protected $guarded = [];

    public function chunks(): HasMany
    {
        return $this->hasMany(RagChunk::class);
    }
}
'@

Write-ProjectFile 'app/Models/RagChunk.php' @'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagChunk extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'embedding' => AsVector::class,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RagDocument::class, 'rag_document_id');
    }
}
'@

# ---------- Chunker ----------
Write-ProjectFile 'app/Rag/Indexing/Chunker.php' @'
<?php

namespace App\Rag\Indexing;

final class Chunker
{
    public function __construct(
        private int $maxChars = 1000,
        private int $overlapChars = 250,
    ) {}

    /**
     * @param  array<int, array{page: int, text: string}>  $pages
     * @return array<int, array{content: string, page_from: int, page_to: int}>
     */
    public function chunk(array $pages): array
    {
        // ۱. شکستن متن هر صفحه به واحدهای کوچک (پاراگراف، جمله، یا تکه‌ی کلمه‌ای)
        $units = [];
        foreach ($pages as $page) {
            foreach ($this->units($page['text']) as $text) {
                $units[] = ['text' => $text, 'page' => $page['page']];
            }
        }

        // ۲. چیدن واحدها کنار هم تا سقف طول تکه، با هم‌پوشانی آخرین واحد کوتاه
        $chunks = [];
        $current = [];
        $length = 0;

        foreach ($units as $unit) {
            $unitLength = mb_strlen($unit['text']);

            if ($current !== [] && $length + $unitLength > $this->maxChars) {
                $chunks[] = $this->build($current);

                $last = end($current);
                $current = mb_strlen($last['text']) <= $this->overlapChars ? [$last] : [];
                $length = $current !== [] ? mb_strlen($last['text']) + 1 : 0;
            }

            $current[] = $unit;
            $length += $unitLength + 1;
        }

        if ($current !== []) {
            $chunks[] = $this->build($current);
        }

        return $chunks;
    }

    /** @return string[] */
    private function units(string $text): array
    {
        $units = [];

        foreach (preg_split('/\n\s*\n/u', $text) as $paragraph) {
            $paragraph = trim(preg_replace('/\s*\n\s*/u', ' ', $paragraph));
            if ($paragraph === '') {
                continue;
            }
            if (mb_strlen($paragraph) <= $this->maxChars) {
                $units[] = $paragraph;
                continue;
            }

            // پاراگراف بلند: شکستن به جمله
            foreach (preg_split('/(?<=[.؟!؛])\s+/u', $paragraph) as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }
                if (mb_strlen($sentence) <= $this->maxChars) {
                    $units[] = $sentence;
                    continue;
                }

                // جمله‌ی خیلی بلند: شکستن بر اساس کلمه
                $buffer = '';
                foreach (preg_split('/\s+/u', $sentence) as $word) {
                    if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($word) + 1 > $this->maxChars) {
                        $units[] = $buffer;
                        $buffer = '';
                    }
                    $buffer = $buffer === '' ? $word : "{$buffer} {$word}";
                }
                if ($buffer !== '') {
                    $units[] = $buffer;
                }
            }
        }

        return $units;
    }

    private function build(array $units): array
    {
        return [
            'content' => implode("\n", array_column($units, 'text')),
            'page_from' => $units[0]['page'],
            'page_to' => end($units)['page'],
        ];
    }
}
'@

# ---------- HybridSearch ----------
Write-ProjectFile 'app/Rag/Search/HybridSearch.php' @'
<?php

namespace App\Rag\Search;

use App\Models\RagChunk;
use App\Rag\Support\PersianNormalizer;
use Laravel\Ai\Embeddings;

final class HybridSearch
{
    public function __construct(
        private string $provider = 'ollama_local',
        private int $candidates = 20,
    ) {}

    /**
     * @param  string[]|null  $audiences  گروه‌های مخاطب مجاز؛ null یعنی بدون فیلتر
     * @return array{results: array, best_similarity: float}
     */
    public function search(string $question, ?array $audiences = null, int $k = 5): array
    {
        $normalized = PersianNormalizer::normalize($question);
        $vector = Embeddings::for([$normalized])->generate($this->provider)->embeddings[0];

        // فیلتر دسترسی داخل خود کوئری‌ها، نه بعد از آن
        $scope = fn ($query) => $query->when(
            $audiences !== null,
            fn ($q) => $q->whereHas('document', fn ($d) => $d->whereIn('audience', $audiences)),
        );

        // ۱. جست‌وجوی برداری
        $vectorHits = $scope(RagChunk::query())
            ->select('id')
            ->selectVectorDistance('embedding', $vector, as: 'distance')
            ->orderByVectorDistance('embedding', $vector)
            ->limit($this->candidates)
            ->get();

        // ۲. جست‌وجوی متنی با منطق «یا»
        $textHits = collect();
        $tsquery = $this->orQuery($question);
        if ($tsquery !== '') {
            $textHits = $scope(RagChunk::query())
                ->select('id')
                ->selectRaw("ts_rank_cd(tsv, to_tsquery('simple', ?)) AS rank", [$tsquery])
                ->whereRaw("tsv @@ to_tsquery('simple', ?)", [$tsquery])
                ->orderByDesc('rank')
                ->limit($this->candidates)
                ->get();
        }

        // ۳. ترکیب با Reciprocal Rank Fusion
        $scores = [];
        foreach ([$vectorHits->pluck('id'), $textHits->pluck('id')] as $list) {
            foreach ($list->values() as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / (60 + $rank + 1);
            }
        }
        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, $k);

        $chunks = RagChunk::with('document')->findMany($ids)->keyBy('id');
        $similarity = $vectorHits->mapWithKeys(fn ($hit) => [$hit->id => 1 - (float) $hit->distance]);
        $textIds = $textHits->pluck('id')->flip();

        $results = [];
        foreach ($ids as $id) {
            $results[] = [
                'chunk' => $chunks[$id],
                'rrf' => $scores[$id],
                'similarity' => $similarity[$id] ?? null,
                'in_vector' => isset($similarity[$id]),
                'in_text' => isset($textIds[$id]),
            ];
        }

        return [
            'results' => $results,
            'best_similarity' => $vectorHits->isEmpty() ? 0.0 : 1 - (float) $vectorHits->first()->distance,
        ];
    }

    /** ساخت tsquery با «یا» از کلمه‌های سه‌حرفی به بالا و عددها؛ فقط حروف و ارقام، پس امن است */
    private function orQuery(string $question): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', PersianNormalizer::searchForm($question), $matches);

        $terms = array_filter(
            array_unique($matches[0]),
            fn ($t) => mb_strlen($t) >= 3 || (ctype_digit($t) && strlen($t) >= 2),
        );

        return implode(' | ', $terms);
    }
}
'@

# ---------- IndexCorpus ----------
Write-ProjectFile 'app/Console/Commands/IndexCorpus.php' @'
<?php

namespace App\Console\Commands;

use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Rag\Indexing\Chunker;
use App\Rag\Support\PersianNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use RuntimeException;

class IndexCorpus extends Command
{
    protected $signature = 'rag:index
        {--provider=ollama_local : provider برای embedding}
        {--batch=16 : تعداد تکه در هر درخواست embedding}
        {--fresh : پاک کردن کل ایندکس و ساخت از اول}
        {--max-chars=1000 : حداکثر طول هر تکه}
        {--overlap=250 : حداکثر طول جمله‌ی هم‌پوشان}';

    protected $description = 'تکه‌تکه کردن متن اسناد، ساخت embedding و ذخیره در PostgreSQL';

    private const DIMENSIONS = 1024;

    public function handle(): int
    {
        $provider = $this->option('provider');
        $model = config("ai.providers.{$provider}.models.embeddings.default") ?? 'unknown';
        $chunker = new Chunker((int) $this->option('max-chars'), (int) $this->option('overlap'));
        $batch = max(1, (int) $this->option('batch'));

        if ($this->option('fresh')) {
            RagChunk::query()->delete();
            RagDocument::query()->delete();
            $this->warn('ایندکس قبلی پاک شد.');
        }

        $files = glob(storage_path('app/corpus/text') . '/*.json') ?: [];
        $totalChunks = 0;
        $totalChars = 0;
        $start = microtime(true);

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $title = $this->cleanTitle($data['original_name'] ?? $data['id']);

            $document = RagDocument::updateOrCreate(
                ['doc_key' => $data['id']],
                [
                    'title' => $title,
                    'audience' => $data['audience'] ?: 'all_students',
                    'file' => $data['file'],
                    'method' => $data['method'] ?? null,
                    'pages' => count($data['pages']),
                ],
            );

            if ($document->chunks()->exists()) {
                $this->line("{$data['id']}: قبلاً ایندکس شده؛ رد شد (برای ساخت دوباره --fresh)");
                continue;
            }

            $docStart = microtime(true);
            $chunks = $chunker->chunk($data['pages']);

            // embedding همه‌ی تکه‌ها، با عنوان سند به‌عنوان زمینه
            $vectors = [];
            foreach (array_chunk($chunks, $batch) as $group) {
                $inputs = array_map(fn ($c) => "عنوان سند: {$title}\n{$c['content']}", $group);
                array_push($vectors, ...Embeddings::for($inputs)->generate($provider)->embeddings);
            }

            DB::transaction(function () use ($chunks, $vectors, $document, $title, $model) {
                foreach ($chunks as $i => $chunk) {
                    if (count($vectors[$i]) !== self::DIMENSIONS) {
                        throw new RuntimeException('ابعاد embedding ' . count($vectors[$i]) . ' است، نه ' . self::DIMENSIONS . '؛ مدل embedding با ستون دیتابیس هماهنگ نیست.');
                    }

                    RagChunk::create([
                        'rag_document_id' => $document->id,
                        'chunk_index' => $i,
                        'page_from' => $chunk['page_from'],
                        'page_to' => $chunk['page_to'],
                        'content' => $chunk['content'],
                        'search_text' => PersianNormalizer::searchForm("{$title}\n{$chunk['content']}"),
                        'embedding_model' => $model,
                        'embedding_dims' => count($vectors[$i]),
                        'embedding' => $vectors[$i],
                    ]);
                }
            });

            $chars = array_sum(array_map(fn ($c) => mb_strlen($c['content']), $chunks));
            $totalChunks += count($chunks);
            $totalChars += $chars;

            $this->line(sprintf(
                '%s: %d تکه، میانگین %d کاراکتر، %dms — %s',
                $data['id'], count($chunks), count($chunks) ? intdiv($chars, count($chunks)) : 0,
                (int) ((microtime(true) - $docStart) * 1000), mb_substr($title, 0, 40),
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            'تمام شد: %d تکه، میانگین %d کاراکتر، کل زمان %d ثانیه (مدل: %s)',
            $totalChunks, $totalChunks ? intdiv($totalChars, $totalChunks) : 0,
            (int) (microtime(true) - $start), $model,
        ));

        return self::SUCCESS;
    }

    private function cleanTitle(string $name): string
    {
        $title = str_replace('_', ' ', $name);
        $title = preg_replace('/[()]+\s*$/u', '', $title);   // پرانتز اضافه‌ی آخر نام فایل

        return PersianNormalizer::normalize(trim($title));
    }
}
'@

# ---------- SearchCorpus ----------
Write-ProjectFile 'app/Console/Commands/SearchCorpus.php' @'
<?php

namespace App\Console\Commands;

use App\Rag\Search\HybridSearch;
use Illuminate\Console\Command;

class SearchCorpus extends Command
{
    protected $signature = 'rag:search
        {question? : سؤال (اگر فارسی درست نرسید، از --file استفاده کن)}
        {--file= : فایل UTF-8 با یک سؤال در هر خط}
        {--audience=* : فیلتر مخاطب، مثلاً --audience=phd}
        {--k=5 : تعداد نتیجه}
        {--provider=ollama_local}';

    protected $description = 'تست جست‌وجوی ترکیبی (برداری + متنی) روی ایندکس';

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

        $audiences = $this->option('audience') ?: null;
        $search = new HybridSearch($this->option('provider'));

        foreach ($questions as $question) {
            $this->newLine();
            $this->info("سؤال: {$question}" . ($audiences ? ' [مخاطب: ' . implode('، ', $audiences) . ']' : ''));

            $start = microtime(true);
            $result = $search->search($question, $audiences, (int) $this->option('k'));
            $ms = (int) ((microtime(true) - $start) * 1000);

            $this->line(sprintf('بیشترین شباهت برداری: %.3f | زمان: %dms', $result['best_similarity'], $ms));

            if ($result['results'] === []) {
                $this->warn('نتیجه‌ای پیدا نشد.');
                continue;
            }

            foreach ($result['results'] as $i => $r) {
                $chunk = $r['chunk'];
                $pages = $chunk->page_from === $chunk->page_to
                    ? "ص {$chunk->page_from}" : "ص {$chunk->page_from}-{$chunk->page_to}";
                $sources = ($r['in_vector'] ? 'V' : '-') . ($r['in_text'] ? 'T' : '-');

                $this->line(sprintf(
                    '<comment>%d. %s</comment> (%s، %s) [%s] شباهت: %s | %s',
                    $i + 1, $chunk->document->doc_key, $chunk->document->audience, $pages, $sources,
                    $r['similarity'] !== null ? sprintf('%.3f', $r['similarity']) : '-',
                    mb_substr($chunk->document->title, 0, 35),
                ));
                $this->line('   ' . mb_substr(preg_replace('/\s+/u', ' ', $chunk->content), 0, 160));
            }
        }

        return self::SUCCESS;
    }
}
'@

Write-Host ''
Write-Host 'تمام شد. قدم‌های بعد:' -ForegroundColor Cyan
Write-Host '  php artisan migrate'
Write-Host '  php artisan rag:index'