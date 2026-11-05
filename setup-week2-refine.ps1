# setup-week2-refine.ps1 — انتخاب بهترین روش برای هر صفحه + حذف خط‌های نویز
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

# ---------- TextQuality ----------
Write-ProjectFile 'app/Rag/Support/TextQuality.php' @'
<?php

namespace App\Rag\Support;

final class TextQuality
{
    // حروف فارسی و عربی (بدون کشیده، ارقام و علائم)
    public const LETTERS = '\x{0621}-\x{063A}\x{0641}-\x{064A}\x{067E}\x{0686}\x{0698}\x{06A9}\x{06AF}\x{06CC}\x{06C0}';

    /** @var array<string, true> */
    private array $lexicon;

    /** @param string[] $words */
    public function __construct(array $words)
    {
        $this->lexicon = array_fill_keys($words, true);
    }

    /** @return string[] کلمه‌ها، بدون نیم‌فاصله (تا «شیوه‌نامه» و «شیوهنامه» یکی شوند) */
    public static function tokens(string $text): array
    {
        preg_match_all('/[' . self::LETTERS . ']+(?:\x{200C}[' . self::LETTERS . ']+)*/u', $text, $matches);

        $tokens = [];
        foreach ($matches[0] as $token) {
            $key = str_replace("\u{200C}", '', $token);
            if (mb_strlen($key) >= 2) {
                $tokens[] = $key;
            }
        }

        return $tokens;
    }

    /** درصد کلمه‌های معتبر (۰ تا ۱۰۰) */
    public function score(string $text): int
    {
        $tokens = self::tokens($text);
        if ($tokens === []) {
            return 0;
        }

        return (int) round(100 * $this->validCount($tokens) / count($tokens));
    }

    /**
     * حذف خط‌های نویز (مهر، امضا، سربرگ خوانده‌شده با OCR).
     *
     * @return array{0: string, 1: string[]} متن تمیز و خط‌های حذف‌شده
     */
    public function cleanLines(string $text, int $threshold): array
    {
        $kept = [];
        $removed = [];

        foreach (explode("\n", $text) as $line) {
            $trim = trim($line);

            if ($trim === '') {
                $kept[] = '';
                continue;
            }

            $tokens = self::tokens($trim);

            if ($tokens === []) {
                // خط بدون کلمه‌ی فارسی: کوتاه = نویز (مثل «1 7»)، بلند = نگه دار (مثل ردیف عددی جدول)
                if (mb_strlen($trim) < 15) {
                    $removed[] = $trim;
                } else {
                    $kept[] = $line;
                }
                continue;
            }

            if (100 * $this->validCount($tokens) / count($tokens) < $threshold) {
                $removed[] = $trim;
            } else {
                $kept[] = $line;
            }
        }

        $clean = preg_replace('/\n{3,}/u', "\n\n", implode("\n", $kept));

        return [trim($clean), $removed];
    }

    private function validCount(array $tokens): int
    {
        return count(array_filter($tokens, fn ($w) => isset($this->lexicon[$w])));
    }
}
'@

# ---------- OcrPdfExtractor (مسیر ابزارها قابل تنظیم) ----------
Write-ProjectFile 'app/Rag/Extraction/OcrPdfExtractor.php' @'
<?php

namespace App\Rag\Extraction;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class OcrPdfExtractor implements DocumentExtractor
{
    public function __construct(
        private int $dpi = 300,
        private string $lang = 'fas',
        private string $tesseract = 'tesseract',
        private string $pdftoppm = 'pdftoppm',
    ) {}

    public function supports(string $path): bool
    {
        return str_ends_with(strtolower($path), '.pdf');
    }

    public function extract(string $path): array
    {
        $tmp = storage_path('app/ocr-tmp/' . pathinfo($path, PATHINFO_FILENAME));
        File::ensureDirectoryExists($tmp);
        File::cleanDirectory($tmp);

        try {
            $render = Process::timeout(900)->run([$this->pdftoppm, '-r', (string) $this->dpi, '-png', $path, $tmp . '/page']);
            if ($render->failed()) {
                throw new RuntimeException('pdftoppm: ' . trim($render->errorOutput()));
            }

            $images = glob($tmp . '/page*.png') ?: [];
            natsort($images);

            if ($images === []) {
                throw new RuntimeException('pdftoppm هیچ تصویری نساخت');
            }

            $pages = [];
            foreach (array_values($images) as $image) {
                $result = Process::timeout(300)->run([$this->tesseract, $image, 'stdout', '-l', $this->lang]);
                if ($result->failed()) {
                    throw new RuntimeException('tesseract: ' . trim($result->errorOutput()));
                }
                $pages[] = $result->output();
            }

            return $pages;
        } finally {
            File::deleteDirectory($tmp);
        }
    }
}
'@

# ---------- CorpusQuality (ذخیره‌ی واژه‌نامه) ----------
Write-ProjectFile 'app/Console/Commands/CorpusQuality.php' @'
<?php

namespace App\Console\Commands;

use App\Rag\Support\TextQuality;
use Illuminate\Console\Command;

class CorpusQuality extends Command
{
    protected $signature = 'rag:quality
        {--min-docs=3 : کلمه باید حداقل در این تعداد سند آمده باشد تا معتبر حساب شود}
        {--threshold=60 : زیر این درصد، سند مشکوک است}';

    protected $description = 'سنجش کیفیت متن با نسبت کلمه‌های معتبر؛ واژه‌نامه را در lexicon.json ذخیره می‌کند';

    public function handle(): int
    {
        $corpus = storage_path('app/corpus');
        $files = glob($corpus . '/text/*.json') ?: [];

        if ($files === []) {
            $this->error('هیچ فایل json پیدا نشد؛ اول rag:extract را اجرا کن.');

            return self::FAILURE;
        }

        $docs = [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $pages = [];
            foreach ($data['pages'] as $page) {
                $pages[$page['page']] = TextQuality::tokens($page['text']);
            }
            $docs[$data['id']] = ['data' => $data, 'pages' => $pages];
        }

        // واژه‌نامه: کلمه‌هایی که در حداقل N سند مختلف آمده‌اند
        $documentFrequency = [];
        foreach ($docs as $doc) {
            foreach (array_unique(array_merge(...array_values($doc['pages']))) as $word) {
                $documentFrequency[$word] = ($documentFrequency[$word] ?? 0) + 1;
            }
        }
        $minDocs = (int) $this->option('min-docs');
        $lexicon = array_filter($documentFrequency, fn ($n) => $n >= $minDocs);

        file_put_contents($corpus . '/lexicon.json', json_encode(array_keys($lexicon), JSON_UNESCAPED_UNICODE));
        $this->info('اندازه‌ی واژه‌نامه: ' . count($lexicon) . " کلمه (حاضر در حداقل {$minDocs} سند) — ذخیره شد در lexicon.json");

        $threshold = (int) $this->option('threshold');
        $rows = [];

        foreach ($docs as $id => $doc) {
            $total = 0;
            $valid = 0;
            $worst = null;

            foreach ($doc['pages'] as $pageNumber => $tokens) {
                $pageValid = count(array_filter($tokens, fn ($w) => isset($lexicon[$w])));
                $total += count($tokens);
                $valid += $pageValid;

                if (count($tokens) >= 20) {
                    $score = (int) round(100 * $pageValid / count($tokens));
                    if ($worst === null || $score < $worst[1]) {
                        $worst = [$pageNumber, $score];
                    }
                }
            }

            $score = $total ? (int) round(100 * $valid / $total) : 0;

            $rows[] = [
                'id' => $id,
                'method' => $doc['data']['method'] ?? '',
                'tokens' => $total,
                'valid_pct' => $score,
                'worst_page' => $worst ? "{$worst[0]}: {$worst[1]}%" : '-',
                'verdict' => $score >= $threshold ? 'OK' : 'SUSPECT',
                'name' => mb_substr($doc['data']['original_name'] ?? '', 0, 30),
            ];
        }

        usort($rows, fn ($a, $b) => $a['valid_pct'] <=> $b['valid_pct']);

        $this->table(['id', 'method', 'tokens', 'valid%', 'worst page', 'verdict', 'name'], $rows);

        foreach (array_unique(array_column($rows, 'method')) as $method) {
            $scores = array_column(array_filter($rows, fn ($r) => $r['method'] === $method), 'valid_pct');
            $this->info(sprintf('میانگین %s: %d%% (%d سند)', $method, array_sum($scores) / count($scores), count($scores)));
        }

        $fh = fopen($corpus . '/quality-report.csv', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ',', '"', '');
        }
        fclose($fh);

        return self::SUCCESS;
    }
}
'@

# ---------- RefineCorpus ----------
Write-ProjectFile 'app/Console/Commands/RefineCorpus.php' @'
<?php

namespace App\Console\Commands;

use App\Rag\Extraction\OcrPdfExtractor;
use App\Rag\Extraction\PdfTextExtractor;
use App\Rag\Support\PersianNormalizer;
use App\Rag\Support\TextQuality;
use Illuminate\Console\Command;
use Throwable;

class RefineCorpus extends Command
{
    protected $signature = 'rag:refine
        {--only= : فقط یک سند، مثلاً doc-08}
        {--tie=3 : اگر OCR کمتر از این مقدار بهتر بود، متن اصلی انتخاب می‌شود (عددهایش دقیق‌ترند)}
        {--line-threshold=30 : خط‌هایی که درصد کلمه‌ی معتبرشان کمتر از این است حذف می‌شوند}
        {--no-clean : بدون حذف خط‌های نویز}';

    protected $description = 'انتخاب بهترین روش (متن یا OCR) برای هر صفحه‌ی PDF و حذف خط‌های نویز';

    public function handle(): int
    {
        $corpus = storage_path('app/corpus');
        $lexiconPath = $corpus . '/lexicon.json';

        if (! is_file($lexiconPath)) {
            $this->error('lexicon.json پیدا نشد؛ اول php artisan rag:quality را اجرا کن.');

            return self::FAILURE;
        }

        $quality = new TextQuality(json_decode(file_get_contents($lexiconPath), true));
        $pdf = new PdfTextExtractor();
        $ocr = new OcrPdfExtractor(
            tesseract: env('TESSERACT_BIN', 'tesseract'),
            pdftoppm: env('PDFTOPPM_BIN', 'pdftoppm'),
        );

        $tie = (int) $this->option('tie');
        $lineThreshold = (int) $this->option('line-threshold');
        $clean = ! $this->option('no-clean');

        $rows = [];

        foreach (glob($corpus . '/text/*.json') ?: [] as $jsonPath) {
            $doc = json_decode(file_get_contents($jsonPath), true);

            if ($this->option('only') && $doc['id'] !== $this->option('only')) {
                continue;
            }
            if (! str_ends_with(strtolower($doc['file']), '.pdf')) {
                continue; // DOCXها تمیزند و دست نمی‌خورند
            }

            $this->line("در حال پردازش {$doc['id']} (متن + OCR) ...");
            $start = microtime(true);
            $before = $quality->score(implode("\n", array_column($doc['pages'], 'text')));

            try {
                $path = $corpus . '/' . $doc['file'];
                $textPages = array_map([PersianNormalizer::class, 'normalize'], $pdf->extract($path));
                $ocrPages = array_map([PersianNormalizer::class, 'normalize'], $ocr->extract($path));

                $chosen = [];
                if (count($textPages) === count($ocrPages)) {
                    foreach ($textPages as $i => $textPage) {
                        $useOcr = $quality->score($ocrPages[$i]) > $quality->score($textPage) + $tie;
                        $chosen[] = $useOcr ? [$ocrPages[$i], 'ocr'] : [$textPage, 'text'];
                    }
                } else {
                    // تعداد صفحه‌ها یکی نیست؛ تصمیم در سطح کل سند
                    $useOcr = $quality->score(implode("\n", $ocrPages)) > $quality->score(implode("\n", $textPages)) + $tie;
                    foreach ($useOcr ? $ocrPages : $textPages as $page) {
                        $chosen[] = [$page, $useOcr ? 'ocr' : 'text'];
                    }
                }

                $pages = [];
                $dropped = '';
                $droppedCount = 0;
                $ocrCount = 0;

                foreach ($chosen as $i => [$text, $source]) {
                    if ($clean) {
                        [$text, $removed] = $quality->cleanLines($text, $lineThreshold);
                        if ($removed !== []) {
                            $dropped .= '===== صفحه ' . ($i + 1) . " =====\n" . implode("\n", $removed) . "\n\n";
                            $droppedCount += count($removed);
                        }
                    }

                    $pages[] = ['page' => $i + 1, 'source' => $source, 'score' => $quality->score($text), 'text' => $text];
                    $ocrCount += $source === 'ocr' ? 1 : 0;
                }

                $doc['method'] = 'best';
                $doc['pages'] = $pages;
                file_put_contents($jsonPath, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $txt = '';
                foreach ($pages as $p) {
                    $txt .= "===== صفحه {$p['page']} ({$p['source']}، {$p['score']}%) =====\n\n{$p['text']}\n\n";
                }
                file_put_contents($corpus . "/text/{$doc['id']}.txt", $txt);
                file_put_contents($corpus . "/text/{$doc['id']}.dropped.txt", $dropped);

                $after = $quality->score(implode("\n", array_column($pages, 'text')));

                $rows[] = [
                    $doc['id'], count($pages), count($pages) - $ocrCount, $ocrCount, $droppedCount,
                    "{$before}%", "{$after}%", (int) ((microtime(true) - $start) * 1000) . 'ms',
                    mb_substr($doc['original_name'] ?? '', 0, 30),
                ];
            } catch (Throwable $e) {
                $rows[] = [$doc['id'], '-', '-', '-', '-', "{$before}%", 'ERROR', '-', mb_substr($e->getMessage(), 0, 60)];
            }
        }

        if ($rows === []) {
            $this->warn('هیچ PDFی پردازش نشد.');

            return self::FAILURE;
        }

        $this->table(['id', 'pages', 'text', 'ocr', 'dropped', 'before', 'after', 'time', 'name'], $rows);
        $this->info('بعد از این، php artisan rag:quality را دوباره اجرا کن تا امتیازهای جدید با واژه‌نامه‌ی به‌روز دیده شوند.');

        return self::SUCCESS;
    }
}
'@

Write-Host ''
Write-Host 'تمام شد. قدم‌های بعد:' -ForegroundColor Cyan
Write-Host '  php artisan rag:quality'
Write-Host '  php artisan rag:refine --only=doc-08'