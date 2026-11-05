# setup-week2-ocr.ps1 — OCR برای PDFهای تصویری + نسخه‌ی جدید rag:extract
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

# ---------- OcrPdfExtractor ----------
Write-ProjectFile 'app/Rag/Extraction/OcrPdfExtractor.php' @'
<?php

namespace App\Rag\Extraction;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class OcrPdfExtractor implements DocumentExtractor
{
    public function __construct(private int $dpi = 300, private string $lang = 'fas') {}

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
            // ۱. تبدیل صفحه‌های PDF به تصویر
            $render = Process::timeout(900)->run(['pdftoppm', '-r', (string) $this->dpi, '-png', $path, $tmp . '/page']);
            if ($render->failed()) {
                throw new RuntimeException('pdftoppm: ' . trim($render->errorOutput()));
            }

            $images = glob($tmp . '/page*.png') ?: [];
            natsort($images);

            if ($images === []) {
                throw new RuntimeException('pdftoppm هیچ تصویری نساخت');
            }

            // ۲. خواندن هر صفحه با Tesseract
            $pages = [];
            foreach (array_values($images) as $image) {
                $result = Process::timeout(300)->run(['tesseract', $image, 'stdout', '-l', $this->lang]);
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

# ---------- ExtractCorpus (نسخه‌ی جدید) ----------
Write-ProjectFile 'app/Console/Commands/ExtractCorpus.php' @'
<?php

namespace App\Console\Commands;

use App\Rag\Extraction\DocumentExtractor;
use App\Rag\Extraction\DocxExtractor;
use App\Rag\Extraction\OcrPdfExtractor;
use App\Rag\Extraction\PdfTextExtractor;
use App\Rag\Support\PersianNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Normalizer;
use RuntimeException;
use Throwable;
use ZipArchive;

class ExtractCorpus extends Command
{
    protected $signature = 'rag:extract
        {--only= : فقط یک سند، مثلاً doc-05}
        {--layout : استفاده از حالت layout در pdftotext}
        {--no-ocr : بدون OCR، حتی برای PDFهای تصویری}
        {--force-ocr : OCR برای همه‌ی PDFها، برای مقایسه}';

    protected $description = 'استخراج متن اسناد پوشه‌ی corpus (متن یا OCR)، نرمال‌سازی فارسی و گزارش کیفیت';

    private const NORMAL_WORDS = ['است', 'این', 'که', 'برای', 'دانشجو', 'آموزشی'];

    private const REVERSED_WORDS = ['تسا', 'نیا', 'هک', 'یارب', 'وجشناد', 'یشزومآ'];

    private const NEEDS_OCR = ['EMPTY', 'SCANNED?', 'LOW_FA'];

    private PdfTextExtractor $pdf;

    private DocxExtractor $docx;

    private OcrPdfExtractor $ocr;

    public function handle(): int
    {
        $dir = storage_path('app/corpus');
        $manifestPath = $dir . '/manifest.csv';

        if (! is_file($manifestPath)) {
            $this->error("manifest.csv پیدا نشد: {$manifestPath}");

            return self::FAILURE;
        }

        $this->checkEnvironment();

        $this->pdf = new PdfTextExtractor((bool) $this->option('layout'));
        $this->docx = new DocxExtractor();
        $this->ocr = new OcrPdfExtractor();

        $outDir = $dir . '/text';
        File::ensureDirectoryExists($outDir);

        $report = [];
        foreach ($this->readManifest($manifestPath) as $doc) {
            if ($this->option('only') && $doc['id'] !== $this->option('only')) {
                continue;
            }
            $this->line("در حال پردازش {$doc['id']} ...");
            $report[] = $this->processDocument($doc, $dir, $outDir);
        }

        if ($report === []) {
            $this->warn('هیچ سندی پردازش نشد.');

            return self::FAILURE;
        }

        $this->table(
            ['id', 'type', 'method', 'pages', 'chars/page', 'fa%', 'pres%', 'status', 'time', 'name'],
            array_map(fn ($r) => [
                $r['id'], $r['type'], $r['method'], $r['pages'], $r['chars_per_page'], $r['persian_pct'],
                $r['presentation_pct'], $r['status'], $r['ms'] . 'ms', mb_substr($r['original_name'], 0, 30),
            ], $report)
        );

        foreach ($report as $r) {
            $this->line("<comment>{$r['id']}</comment> [{$r['method']}/{$r['status']}] {$r['sample']}");
        }

        if (! $this->option('only')) {
            $this->writeReport($dir . '/extraction-report.csv', $report);
        }

        $this->newLine();
        foreach (array_count_values(array_column($report, 'status')) as $status => $count) {
            $this->info("{$status}: {$count}");
        }
        foreach (array_count_values(array_column($report, 'method')) as $method => $count) {
            $this->info("method {$method}: {$count}");
        }

        return self::SUCCESS;
    }

    private function processDocument(array $doc, string $dir, string $outDir): array
    {
        $path = $dir . '/' . $doc['file'];
        $start = microtime(true);
        $type = strtolower(pathinfo($doc['file'], PATHINFO_EXTENSION));

        $row = [
            'id' => $doc['id'],
            'type' => $type,
            'method' => '',
            'audience' => $doc['audience'] ?? '',
            'original_name' => $doc['original_name'] ?? '',
            'pages' => 0,
            'chars' => 0,
            'chars_per_page' => 0,
            'persian_pct' => 0,
            'presentation_pct' => 0,
            'normal_words' => 0,
            'reversed_words' => 0,
            'status' => '',
            'ms' => 0,
            'sample' => '',
        ];

        try {
            if (! is_file($path)) {
                throw new RuntimeException("فایل پیدا نشد: {$doc['file']}");
            }

            if ($type === 'docx') {
                [$pages, $metrics] = $this->runExtractor($this->docx, $path, $type);
                $method = 'docx';
            } elseif ($type === 'pdf') {
                if ($this->option('force-ocr')) {
                    [$pages, $metrics] = $this->runExtractor($this->ocr, $path, $type);
                    $method = 'ocr';
                } else {
                    [$pages, $metrics] = $this->runExtractor($this->pdf, $path, $type);
                    $method = 'text';

                    if (! $this->option('no-ocr') && in_array($metrics['status'], self::NEEDS_OCR, true)) {
                        $this->line("  {$doc['id']}: {$metrics['status']} ← OCR ...");
                        [$pages, $metrics] = $this->runExtractor($this->ocr, $path, $type);
                        $method = 'ocr';
                    }
                }
            } else {
                throw new RuntimeException('فرمت پشتیبانی نمی‌شود');
            }

            $row = array_merge($row, $metrics, ['method' => $method]);

            file_put_contents("{$outDir}/{$doc['id']}.json", json_encode([
                'id' => $doc['id'],
                'file' => $doc['file'],
                'original_name' => $row['original_name'],
                'audience' => $row['audience'],
                'method' => $method,
                'pages' => array_map(fn ($text, $i) => ['page' => $i + 1, 'text' => $text], $pages, array_keys($pages)),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $txt = '';
            foreach ($pages as $i => $text) {
                $txt .= '===== صفحه ' . ($i + 1) . " =====\n\n{$text}\n\n";
            }
            file_put_contents("{$outDir}/{$doc['id']}.txt", $txt);
        } catch (Throwable $e) {
            $row['status'] = 'ERROR';
            $row['sample'] = mb_substr($e->getMessage(), 0, 150);
        }

        $row['ms'] = (int) ((microtime(true) - $start) * 1000);

        return $row;
    }

    /** @return array{0: string[], 1: array} صفحه‌های نرمال‌شده و معیارهای کیفیت */
    private function runExtractor(DocumentExtractor $extractor, string $path, string $type): array
    {
        $rawPages = $extractor->extract($path);
        $raw = implode("\n", $rawPages);
        $pages = array_map([PersianNormalizer::class, 'normalize'], $rawPages);
        $normalized = implode("\n", $pages);

        $chars = mb_strlen(preg_replace('/\s+/u', '', $normalized));
        $letters = preg_match_all('/\p{L}/u', $raw);
        $arabicLetters = preg_match_all('/(?=\p{L})\p{Arabic}/u', $raw);
        $presentation = preg_match_all('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFC}]/u', $raw);

        $metrics = [
            'pages' => count($pages),
            'chars' => $chars,
            'chars_per_page' => count($pages) ? intdiv($chars, count($pages)) : 0,
            'persian_pct' => $letters ? (int) round(100 * $arabicLetters / $letters) : 0,
            'presentation_pct' => $arabicLetters ? (int) round(100 * $presentation / $arabicLetters) : 0,
            'normal_words' => $this->countWords($normalized, self::NORMAL_WORDS),
            'reversed_words' => $this->countWords($normalized, self::REVERSED_WORDS),
            'sample' => mb_substr(preg_replace('/\s+/u', ' ', $normalized), 0, 90),
        ];
        $metrics['status'] = $this->status($metrics, $type);

        return [$pages, $metrics];
    }

    private function status(array $m, string $type): string
    {
        if ($m['chars'] === 0) {
            return 'EMPTY';
        }
        if ($type === 'pdf' && $m['chars_per_page'] < 100) {
            return 'SCANNED?';
        }
        if ($m['reversed_words'] >= 3 && $m['reversed_words'] > $m['normal_words']) {
            return 'REVERSED';
        }
        if ($m['persian_pct'] < 50) {
            return 'LOW_FA';
        }

        return 'OK';
    }

    private function countWords(string $text, array $words): int
    {
        $count = 0;
        foreach ($words as $word) {
            $count += preg_match_all('/(?<!\p{L})' . preg_quote($word, '/') . '(?!\p{L})/u', $text);
        }

        return $count;
    }

    private function readManifest(string $path): array
    {
        $content = file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = iconv('Windows-1256', 'UTF-8//IGNORE', $content);
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $first = array_shift($lines);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map('trim', str_getcsv($first, $delimiter, '"', ''));

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter, '"', '');
            $rows[] = array_combine($header, array_slice(array_pad($values, count($header), ''), 0, count($header)));
        }

        return $rows;
    }

    private function writeReport(string $path, array $report): void
    {
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_keys($report[0]), ',', '"', '');
        foreach ($report as $row) {
            fputcsv($fh, $row, ',', '"', '');
        }
        fclose($fh);

        $this->info("گزارش ذخیره شد: {$path}");
    }

    private function checkEnvironment(): void
    {
        $tools = ['pdftotext' => ['-v'], 'pdftoppm' => ['-v'], 'tesseract' => ['--list-langs']];

        foreach ($tools as $tool => $args) {
            try {
                $result = Process::run([$tool, ...$args]);
                $output = strtolower($result->output() . $result->errorOutput());
                $found = $result->successful() || str_contains($output, $tool);
            } catch (Throwable) {
                $output = '';
                $found = false;
            }

            if (! $found) {
                $this->warn("{$tool} پیدا نشد؛ آن را نصب و به PATH اضافه کن.");
            } elseif ($tool === 'tesseract' && ! str_contains($output, 'fas')) {
                $this->warn('مدل زبان فارسی (fas) برای Tesseract نصب نیست؛ OCR فارسی کار نمی‌کند.');
            }
        }

        if (! class_exists(ZipArchive::class)) {
            $this->warn('اکستنشن zip فعال نیست؛ DOCXها خوانده نمی‌شوند.');
        }
        if (! class_exists(Normalizer::class)) {
            $this->warn('اکستنشن intl فعال نیست؛ شکل‌های نمایشی عربی اصلاح نمی‌شوند.');
        }
    }
}
'@

Write-Host ''
Write-Host 'تمام شد. قدم بعد:' -ForegroundColor Cyan
Write-Host '  php artisan rag:extract'