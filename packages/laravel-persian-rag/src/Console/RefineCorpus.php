<?php

namespace Js199069\PersianRag\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Js199069\PersianRag\Extraction\OcrPdfExtractor;
use Js199069\PersianRag\Extraction\PdfTextExtractor;
use Js199069\PersianRag\Support\PersianNormalizer;
use Js199069\PersianRag\Support\TextQuality;
use Throwable;

class RefineCorpus extends Command
{
    protected $signature = 'rag:refine
        {--only= : فقط یک سند، مثلاً doc-08}
        {--tie=3 : اگر OCR کمتر از این مقدار بهتر بود، متن اصلی انتخاب می‌شود (عددهایش دقیق‌ترند)}
        {--no-clean : بدون حذف خط‌های نویز}
        {--min-lexicon=400 : کمتر از این تعداد کلمه در واژه‌نامه، حذف نویز انجام نمی‌شود (از دو نمونه: ۱۰۶ کلمه با ۳ سند، ۸۹۲ با ۳۱ سند)}
        {--fresh-ocr : نادیده گرفتن کش و انجام دوباره‌ی OCR}';

    protected $description = 'انتخاب بهترین روش (متن یا OCR) برای هر صفحه‌ی PDF و حذف محافظه‌کارانه‌ی نویز OCR';

    public function handle(): int
    {
        $corpus = rtrim(config('persian-rag.corpus_path'), '/\\');
        $lexiconPath = $corpus . '/lexicon.json';

        if (! is_file($lexiconPath)) {
            $this->error('lexicon.json پیدا نشد؛ اول php artisan rag:quality را اجرا کن.');

            return self::FAILURE;
        }

        $lexicon = json_decode(file_get_contents($lexiconPath), true) ?: [];
        $lexiconSize = isset($lexicon['words']) && is_array($lexicon['words']) ? count($lexicon['words']) : count($lexicon);
        $quality = new TextQuality($lexicon);

        $clean = ! $this->option('no-clean');
        $minLexicon = (int) $this->option('min-lexicon');
        $this->line("واژه‌نامه: {$lexiconSize} کلمه");

        // واژه‌نامه‌ی کوچک (مجموعه‌ی کم‌سند، بدون DOCX) کلمه‌های واقعی را «ناشناخته» می‌بیند،
        // و حذف نویز متن واقعی را هم حذف می‌کند. حذف اشتباه بدتر از نگه‌داشتن نویز است.
        if ($clean && $lexiconSize < $minLexicon) {
            $this->warn("واژه‌نامه کوچک‌تر از {$minLexicon} کلمه است؛ حذف نویز انجام نمی‌شود، چون متن واقعی هم حذف می‌شد. (برای اجبار: --min-lexicon=0)");
            $clean = false;
        }

        $pdf = new PdfTextExtractor(binary: config('persian-rag.extraction.pdftotext'));
        $ocr = new OcrPdfExtractor(
            dpi: (int) config('persian-rag.extraction.ocr_dpi'),
            lang: config('persian-rag.extraction.ocr_language'),
            tesseract: config('persian-rag.extraction.tesseract'),
            pdftoppm: config('persian-rag.extraction.pdftoppm'),
            tmpPath: config('persian-rag.extraction.ocr_tmp_path'),
        );

        $cacheDir = $corpus . '/ocr-cache';
        File::ensureDirectoryExists($cacheDir);

        $tie = (int) $this->option('tie');
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
        $this->info('بعد از این، php artisan rag:quality را دوباره اجرا کن تا امتیازهای جدید دیده شوند.');
        $this->line('<comment>توجه: rag:quality واژه‌نامه را از نو می‌سازد؛ در مجموعه‌ای بدون DOCX، بعد از refine ممکن است خیلی کوچک شود.</comment>');

        return self::SUCCESS;
    }
}
