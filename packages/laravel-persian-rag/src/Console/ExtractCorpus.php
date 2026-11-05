<?php

namespace Js199069\PersianRag\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Js199069\PersianRag\Extraction\DocumentExtractor;
use Js199069\PersianRag\Extraction\DocxExtractor;
use Js199069\PersianRag\Extraction\OcrPdfExtractor;
use Js199069\PersianRag\Extraction\PdfTextExtractor;
use Js199069\PersianRag\Support\Manifest;
use Js199069\PersianRag\Support\PersianNormalizer;
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
        $dir = rtrim(config('persian-rag.corpus_path'), '/\\');
        $manifestPath = $dir . '/manifest.csv';

        if (! is_file($manifestPath)) {
            $this->error("manifest.csv پیدا نشد: {$manifestPath}");

            return self::FAILURE;
        }

        $this->checkEnvironment();

        $this->pdf = new PdfTextExtractor((bool) $this->option('layout'), config('persian-rag.extraction.pdftotext'));
        $this->docx = new DocxExtractor();
        $this->ocr = new OcrPdfExtractor(
            dpi: (int) config('persian-rag.extraction.ocr_dpi'),
            lang: config('persian-rag.extraction.ocr_language'),
            tesseract: config('persian-rag.extraction.tesseract'),
            pdftoppm: config('persian-rag.extraction.pdftoppm'),
            tmpPath: config('persian-rag.extraction.ocr_tmp_path'),
        );

        $outDir = $dir . '/text';
        File::ensureDirectoryExists($outDir);

        $report = [];
        foreach (Manifest::read($manifestPath) as $doc) {
            if ($this->option('only') && $doc['id'] !== $this->option('only')) {
                continue;
            }
            $this->line("در حال پردازش {$doc['id']} ...");
            $report[] = $this->processDocument($doc, $dir, $outDir);
        }

        $missing = array_values(array_filter($report, fn ($r) => $r['status'] === 'MISSING'));
        $report = array_values(array_filter($report, fn ($r) => $r['status'] !== 'MISSING'));
        if ($missing !== []) {
            $missingIds = array_column($missing, 'id');
            $this->warn(sprintf(
                '%d سند در manifest هست ولی فایلش در پوشه نیست (رد شد): %s',
                count($missingIds),
                implode('، ', array_slice($missingIds, 0, 10)) . (count($missingIds) > 10 ? '، ...' : ''),
            ));
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
                // فایل نیست: خطای پردازش نیست، فقط سندی که هنوز اضافه نشده
                $row['status'] = 'MISSING';
                $row['sample'] = "فایل پیدا نشد: {$doc['file']}";

                return $row;
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
        $tools = [
            'pdftotext' => [config('persian-rag.extraction.pdftotext'), ['-v']],
            'pdftoppm' => [config('persian-rag.extraction.pdftoppm'), ['-v']],
            'tesseract' => [config('persian-rag.extraction.tesseract'), ['--list-langs']],
        ];
        $language = config('persian-rag.extraction.ocr_language', 'fas');

        foreach ($tools as $tool => [$binary, $args]) {
            try {
                $result = Process::run([$binary, ...$args]);
                $output = strtolower($result->output() . $result->errorOutput());
                $found = $result->successful() || str_contains($output, $tool);
            } catch (Throwable) {
                $output = '';
                $found = false;
            }

            if (! $found) {
                $this->warn("{$tool} پیدا نشد ({$binary})؛ آن را نصب و به PATH اضافه کن، یا مسیرش را در .env بده.");
            } elseif ($tool === 'tesseract' && ! str_contains($output, $language)) {
                $this->warn("مدل زبان «{$language}» برای Tesseract نصب نیست؛ OCR کار نمی‌کند.");
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
