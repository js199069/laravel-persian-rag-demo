<?php

namespace Js199069\PersianRag\Console;

use Js199069\PersianRag\Extraction\OcrPdfExtractor;
use Js199069\PersianRag\Extraction\PdfTextExtractor;
use Js199069\PersianRag\Support\PersianNormalizer;
use Js199069\PersianRag\Support\TextQuality;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class RefineCorpus extends Command
{
    protected $signature = 'rag:refine
        {--only= : فقط یک سند، مثلاً doc-08}
        {--tie=3 : اگر OCR کمتر از این مقدار بهتر بود، متن اصلی انتخاب می‌شود (عددهایش دقیق‌ترند)}
        {--no-clean : بدون حذف خط‌های نویز}
        {--fresh-ocr : نادیده گرفتن کش و انجام دوباره‌ی OCR}';

    protected $description = 'انتخاب بهترین روش (متن یا OCR) برای هر صفحه‌ی PDF و حذف محافظه‌کارانه‌ی نویز OCR';

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

        $cacheDir = $corpus . '/ocr-cache';
        File::ensureDirectoryExists($cacheDir);

        $tie = (int) $this->option('tie');
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

            $this->line("در حال پردازش {$doc['id']} ...");
            $start = microtime(true);
            $before = $quality->score(implode("\n", array_column($doc['pages'], 'text')));

            try {
                $path = $corpus . '/' . $doc['file'];
                $textPages = array_map([PersianNormalizer::class, 'normalize'], $pdf->extract($path));

                // OCR از کش، یا اجرا و ذخیره در کش
                $cachePath = "{$cacheDir}/{$doc['id']}.json";
                if (is_file($cachePath) && ! $this->option('fresh-ocr')) {
                    $ocrRaw = json_decode(file_get_contents($cachePath), true);
                } else {
                    $this->line('  OCR ...');
                    $ocrRaw = $ocr->extract($path);
                    file_put_contents($cachePath, json_encode($ocrRaw, JSON_UNESCAPED_UNICODE));
                }
                $ocrPages = array_map([PersianNormalizer::class, 'normalize'], $ocrRaw);

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
                    // نویز مهر و امضا فقط در خروجی OCR است؛ لایه‌ی متن دست نمی‌خورد
                    if ($clean && $source === 'ocr') {
                        [$text, $removed] = $quality->cleanLines($text);
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