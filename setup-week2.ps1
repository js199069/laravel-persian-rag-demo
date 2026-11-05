# setup-week2.ps1 — فایل‌های هفته‌ی دوم: استخراج و نرمال‌سازی
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

# ---------- PersianNormalizer ----------
Write-ProjectFile 'app/Rag/Support/PersianNormalizer.php' @'
<?php

namespace App\Rag\Support;

use Normalizer;

final class PersianNormalizer
{
    private const MAP = [
        'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** متن تمیز برای embedding و نمایش؛ خط‌های جدید حفظ می‌شوند */
    public static function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // اصلاح «شکل‌های نمایشی» عربی که در PDFهای قدیمی رایج است (نیاز به اکستنشن intl)
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        }

        $text = strtr($text, self::MAP);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);                // اعراب و کشیده
        $text = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{00AD}\x{FEFF}]/u', '', $text); // نویسه‌های کنترلی نامرئی
        $text = preg_replace('/[ \t]*\x{200C}[ \t]*/u', "\u{200C}", $text);                      // نیم‌فاصله‌ی تمیز
        $text = preg_replace('/\x{200C}{2,}/u', "\u{200C}", $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /** شکل مخصوص جست‌وجوی متنی: نیم‌فاصله ← فاصله، همه‌چیز در یک خط */
    public static function searchForm(string $text): string
    {
        return preg_replace('/\s+/u', ' ', str_replace("\u{200C}", ' ', self::normalize($text)));
    }
}
'@

# ---------- DocumentExtractor ----------
Write-ProjectFile 'app/Rag/Extraction/DocumentExtractor.php' @'
<?php

namespace App\Rag\Extraction;

interface DocumentExtractor
{
    public function supports(string $path): bool;

    /**
     * متن خام سند، صفحه به صفحه.
     *
     * @return string[]
     */
    public function extract(string $path): array;
}
'@

# ---------- PdfTextExtractor ----------
Write-ProjectFile 'app/Rag/Extraction/PdfTextExtractor.php' @'
<?php

namespace App\Rag\Extraction;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class PdfTextExtractor implements DocumentExtractor
{
    public function __construct(private bool $layout = false) {}

    public function supports(string $path): bool
    {
        return str_ends_with(strtolower($path), '.pdf');
    }

    public function extract(string $path): array
    {
        $command = ['pdftotext', '-enc', 'UTF-8'];
        if ($this->layout) {
            $command[] = '-layout';
        }
        $command[] = $path;
        $command[] = '-';

        $result = Process::timeout(120)->run($command);

        if ($result->failed()) {
            throw new RuntimeException('pdftotext: ' . trim($result->errorOutput() ?: $result->output()));
        }

        // pdftotext آخر هر صفحه یک form feed می‌گذارد
        $pages = explode("\f", $result->output());
        if ($pages !== [] && trim(end($pages)) === '') {
            array_pop($pages);
        }

        return $pages;
    }
}
'@

# ---------- DocxExtractor ----------
Write-ProjectFile 'app/Rag/Extraction/DocxExtractor.php' @'
<?php

namespace App\Rag\Extraction;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

final class DocxExtractor implements DocumentExtractor
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function supports(string $path): bool
    {
        return str_ends_with(strtolower($path), '.docx');
    }

    public function extract(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('اکستنشن zip در PHP فعال نیست');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('فایل DOCX باز نشد');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('word/document.xml در فایل نیست');
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);

        $body = $xp->query('//w:body')->item(0);
        if (! $body) {
            return [''];
        }

        $lines = [];
        foreach ($body->childNodes as $node) {
            if ($node->localName === 'p') {
                $lines[] = $this->paragraphText($xp, $node);
            } elseif ($node->localName === 'tbl') {
                // هر ردیف جدول یک خط؛ سلول‌ها با | جدا می‌شوند
                foreach ($xp->query('.//w:tr', $node) as $tr) {
                    $cells = [];
                    foreach ($xp->query('./w:tc', $tr) as $tc) {
                        $parts = [];
                        foreach ($xp->query('.//w:p', $tc) as $p) {
                            $parts[] = $this->paragraphText($xp, $p);
                        }
                        $cells[] = trim(implode(' ', $parts));
                    }
                    $lines[] = implode(' | ', $cells);
                }
            }
        }

        // DOCX مفهوم صفحه ندارد؛ کل سند یک «صفحه» است
        return [implode("\n", $lines)];
    }

    private function paragraphText(DOMXPath $xp, DOMNode $p): string
    {
        $text = '';
        foreach ($xp->query('.//w:t | .//w:tab | .//w:br', $p) as $n) {
            $text .= match ($n->localName) {
                't' => $n->textContent,
                'tab' => ' ',
                'br' => "\n",
                default => '',
            };
        }

        return $text;
    }
}
'@

# ---------- ExtractCorpus command ----------
Write-ProjectFile 'app/Console/Commands/ExtractCorpus.php' @'
<?php

namespace App\Console\Commands;

use App\Rag\Extraction\DocumentExtractor;
use App\Rag\Extraction\DocxExtractor;
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
        {--layout : استفاده از حالت layout در pdftotext}';

    protected $description = 'استخراج متن اسناد پوشه‌ی corpus، نرمال‌سازی فارسی و گزارش کیفیت';

    private const NORMAL_WORDS = ['است', 'این', 'که', 'برای', 'دانشجو', 'آموزشی'];

    private const REVERSED_WORDS = ['تسا', 'نیا', 'هک', 'یارب', 'وجشناد', 'یشزومآ'];

    public function handle(): int
    {
        $dir = storage_path('app/corpus');
        $manifestPath = $dir . '/manifest.csv';

        if (! is_file($manifestPath)) {
            $this->error("manifest.csv پیدا نشد: {$manifestPath}");

            return self::FAILURE;
        }

        $this->checkEnvironment();

        /** @var DocumentExtractor[] $extractors */
        $extractors = [new PdfTextExtractor((bool) $this->option('layout')), new DocxExtractor()];

        $outDir = $dir . '/text';
        File::ensureDirectoryExists($outDir);

        $report = [];
        foreach ($this->readManifest($manifestPath) as $doc) {
            if ($this->option('only') && $doc['id'] !== $this->option('only')) {
                continue;
            }
            $this->line("در حال پردازش {$doc['id']} ...");
            $report[] = $this->processDocument($doc, $dir, $outDir, $extractors);
        }

        if ($report === []) {
            $this->warn('هیچ سندی پردازش نشد.');

            return self::FAILURE;
        }

        $this->table(
            ['id', 'type', 'pages', 'chars/page', 'fa%', 'pres%', 'status', 'time', 'name'],
            array_map(fn ($r) => [
                $r['id'], $r['type'], $r['pages'], $r['chars_per_page'], $r['persian_pct'],
                $r['presentation_pct'], $r['status'], $r['ms'] . 'ms', mb_substr($r['original_name'], 0, 30),
            ], $report)
        );

        foreach ($report as $r) {
            $this->line("<comment>{$r['id']}</comment> [{$r['status']}] {$r['sample']}");
        }

        if (! $this->option('only')) {
            $this->writeReport($dir . '/extraction-report.csv', $report);
        }

        $this->newLine();
        foreach (array_count_values(array_column($report, 'status')) as $status => $count) {
            $this->info("{$status}: {$count}");
        }

        return self::SUCCESS;
    }

    private function processDocument(array $doc, string $dir, string $outDir, array $extractors): array
    {
        $path = $dir . '/' . $doc['file'];
        $start = microtime(true);

        $row = [
            'id' => $doc['id'],
            'type' => strtolower(pathinfo($doc['file'], PATHINFO_EXTENSION)),
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

            $extractor = collect($extractors)->first(fn (DocumentExtractor $e) => $e->supports($path))
                ?? throw new RuntimeException('فرمت پشتیبانی نمی‌شود');

            $rawPages = $extractor->extract($path);
            $raw = implode("\n", $rawPages);
            $pages = array_map([PersianNormalizer::class, 'normalize'], $rawPages);
            $normalized = implode("\n", $pages);

            $chars = mb_strlen(preg_replace('/\s+/u', '', $normalized));
            $letters = preg_match_all('/\p{L}/u', $raw);
            $arabic = preg_match_all('/\p{Arabic}/u', $raw);
            $presentation = preg_match_all('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFC}]/u', $raw);

            $row['pages'] = count($pages);
            $row['chars'] = $chars;
            $row['chars_per_page'] = $row['pages'] ? intdiv($chars, $row['pages']) : 0;
            $row['persian_pct'] = $letters ? (int) round(100 * $arabic / $letters) : 0;
            $row['presentation_pct'] = $arabic ? (int) round(100 * $presentation / $arabic) : 0;
            $row['normal_words'] = $this->countWords($normalized, self::NORMAL_WORDS);
            $row['reversed_words'] = $this->countWords($normalized, self::REVERSED_WORDS);
            $row['status'] = $this->status($row);
            $row['sample'] = mb_substr(preg_replace('/\s+/u', ' ', $normalized), 0, 90);

            file_put_contents("{$outDir}/{$doc['id']}.json", json_encode([
                'id' => $doc['id'],
                'file' => $doc['file'],
                'original_name' => $row['original_name'],
                'audience' => $row['audience'],
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

    private function status(array $r): string
    {
        if ($r['chars'] === 0) {
            return 'EMPTY';
        }
        if ($r['type'] === 'pdf' && $r['chars_per_page'] < 100) {
            return 'SCANNED?';
        }
        if ($r['reversed_words'] >= 3 && $r['reversed_words'] > $r['normal_words']) {
            return 'REVERSED';
        }
        if ($r['persian_pct'] < 50) {
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

        // اگر اکسل فایل را با انکدینگ ویندوز فارسی ذخیره کرده باشد
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
        fwrite($fh, "\xEF\xBB\xBF"); // تا اکسل فارسی را درست نشان دهد
        fputcsv($fh, array_keys($report[0]), ',', '"', '');
        foreach ($report as $row) {
            fputcsv($fh, $row, ',', '"', '');
        }
        fclose($fh);

        $this->info("گزارش ذخیره شد: {$path}");
    }

    private function checkEnvironment(): void
    {
        try {
            $result = Process::run(['pdftotext', '-v']);
            $found = str_contains(strtolower($result->output() . $result->errorOutput()), 'pdftotext');
        } catch (Throwable) {
            $found = false;
        }

        if (! $found) {
            $this->warn('pdftotext پیدا نشد؛ PDFها خطا می‌دهند. Poppler را نصب و به PATH اضافه کن.');
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