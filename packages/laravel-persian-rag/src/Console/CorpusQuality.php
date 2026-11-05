<?php

namespace Js199069\PersianRag\Console;

use Js199069\PersianRag\Support\TextQuality;
use Illuminate\Console\Command;

class CorpusQuality extends Command
{
    protected $signature = 'rag:quality
    {--min-docs=2 : کلمه باید حداقل در منابع تمیز این تعداد سند آمده باشد}
    {--all-sources : واژه‌نامه از همه‌ی اسناد، نه فقط منابع تمیز}
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
    $method = $data['method'] ?? '';
    $pages = [];
    $clean = [];

    foreach ($data['pages'] as $page) {
        $tokens = TextQuality::tokens($page['text']);
        $pages[$page['page']] = $tokens;

        // منبع تمیز: DOCX، متن اصلی PDF، یا صفحه‌ای که rag:refine از متن اصلی انتخاب کرده
        $isClean = in_array($method, ['docx', 'text'], true)
            || ($method === 'best' && ($page['source'] ?? '') === 'text');

        if ($isClean || $this->option('all-sources')) {
            $clean[] = $tokens;
        }
    }

    $docs[$data['id']] = ['data' => $data, 'pages' => $pages, 'clean' => $clean];
}

// واژه‌نامه: کلمه‌هایی که در منابع تمیز حداقل N سند مختلف آمده‌اند (خروجی OCR وارد نمی‌شود)
$documentFrequency = [];
foreach ($docs as $doc) {
    foreach (array_unique(array_merge(...$doc['clean'])) as $word) {
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