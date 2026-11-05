<?php

namespace Js199069\PersianRag\Console;

use Js199069\PersianRag\Search\HybridSearch;
use Illuminate\Console\Command;

class EvaluateSearch extends Command
{
    protected $signature = 'rag:eval
        {--file=storage/app/eval.csv : فایل سؤال‌های ارزیابی}
        {--k=5 : تعداد نتیجه‌ی بررسی‌شده}
        {--provider=ollama_local}';

    protected $description = 'ارزیابی جست‌وجو: پیدا کردن سند و صفحه‌ی درست، نشت دسترسی، و پیشنهاد آستانه‌ی «جواب نده»';

    public function handle(): int
    {
        $file = $this->option('file');
        $path = is_file($file) ? $file : base_path($file);

        if (! is_file($path)) {
            $this->error("فایل ارزیابی پیدا نشد: {$path}");

            return self::FAILURE;
        }

        $search = new HybridSearch($this->option('provider'));
        $k = (int) $this->option('k');

        $table = [];
        $answerable = [];
        $negativeSimilarities = [];
        $accessChecks = [];

        foreach ($this->readCsv($path) as $row) {
            $role = trim($row['role'] ?? '');
            $audiences = null;
            if ($role !== '') {
                $audiences = config("persian-rag.roles.{$role}");
                if ($audiences === null) {
                    $this->error("سؤال {$row['id']}: نقش «{$role}» در config/rag.php تعریف نشده.");

                    return self::FAILURE;
                }
            }

            $result = $search->search($row['question'], $audiences, $k);
            $docs = array_map(fn ($r) => $r['chunk']->document->doc_key, $result['results']);
            $best = $result['best_similarity'];
            $expectedDocs = array_values(array_filter(array_map('trim', explode('|', $row['expected_doc'] ?? ''))));
            $mustNotDoc = trim($row['must_not_doc'] ?? '');

            // تست دسترسی
            if ($mustNotDoc !== '') {
                $ok = ! in_array($mustNotDoc, $docs, true);
                $accessChecks[] = $ok;
                $table[] = [$row['id'], 'access', $ok ? '✅' : '❌ نشت', '-', sprintf('%.3f', $best), $docs[0] ?? '-'];
                continue;
            }

            // سؤال بی‌جواب
            if ($expectedDocs === []) {
                $negativeSimilarities[] = $best;
                $table[] = [$row['id'], 'negative', '-', '-', sprintf('%.3f', $best), $docs[0] ?? '-'];
                continue;
            }

            // سؤال جواب‌دار
// سؤال جواب‌دار: بهترین رتبه در بین همه‌ی سندهای درست
$ranks = array_filter(
    array_map(fn ($d) => array_search($d, $docs, true), $expectedDocs),
    fn ($r) => $r !== false,
);
$rank = $ranks === [] ? false : min($ranks);
$page = (int) ($row['expected_page'] ?? 0);
$pageHit = $page > 0 && collect($result['results'])->contains(
    fn ($r) => in_array($r['chunk']->document->doc_key, $expectedDocs, true)
        && $r['chunk']->page_from <= $page && $r['chunk']->page_to >= $page,
);

$answerable[] = ['rank' => $rank, 'page_hit' => $pageHit, 'has_page' => $page > 0, 'best' => $best];
$label = $rank === false ? '❌' : '✅ @' . ($rank + 1);
$table[] = [$row['id'], 'answer', $label, $page > 0 ? ($pageHit ? '✅' : '❌') : '-', sprintf('%.3f', $best), $docs[0] ?? '-'];
        }

        $this->table(['id', 'type', 'doc', 'page', 'best sim', 'top doc'], $table);

        if ($answerable !== []) {
            $n = count($answerable);
            $hit1 = count(array_filter($answerable, fn ($a) => $a['rank'] === 0));
            $hitK = count(array_filter($answerable, fn ($a) => $a['rank'] !== false));
            $withPage = array_filter($answerable, fn ($a) => $a['has_page']);
$pages = count(array_filter($withPage, fn ($a) => $a['page_hit']));
            $mrr = array_sum(array_map(fn ($a) => $a['rank'] === false ? 0 : 1 / ($a['rank'] + 1), $answerable)) / $n;

            $this->info(sprintf(
                'سؤال‌های جواب‌دار (%d): سند درست در رتبه‌ی ۱: %d%% | در %d نتیجه‌ی اول: %d%% | صفحه‌ی درست: %d%% | MRR: %.2f',
                $n, 100 * $hit1 / $n, $k, 100 * $hitK / $n, count($withPage) ? 100 * $pages / count($withPage) : 0, $mrr,
            ));
        }

        if ($accessChecks !== []) {
            $leaks = count(array_filter($accessChecks, fn ($ok) => ! $ok));
            $this->info(sprintf('تست‌های دسترسی (%d): %d نشت', count($accessChecks), $leaks));
        }

        // آستانه فقط با سؤال‌هایی تنظیم می‌شود که سند درستشان پیدا شده
        $found = array_column(array_filter($answerable, fn ($a) => $a['rank'] !== false), 'best');
        $this->thresholdSweep($found, $negativeSimilarities);

        return self::SUCCESS;
    }

    private function thresholdSweep(array $positive, array $negative): void
    {
        if ($positive === [] || $negative === []) {
            $this->warn('برای پیشنهاد آستانه، هم سؤال جواب‌دارِ پیدا‌شده لازم است و هم سؤال بی‌جواب.');

            return;
        }

        $rows = [];
        $best = null;
        $total = count($positive) + count($negative);

        for ($t = 0.40; $t <= 0.80001; $t += 0.02) {
            $falseRefusals = count(array_filter($positive, fn ($s) => $s < $t));
            $falseAnswers = count(array_filter($negative, fn ($s) => $s >= $t));
            $correct = $total - $falseRefusals - $falseAnswers;

            $rows[] = [sprintf('%.2f', $t), $falseRefusals, $falseAnswers, "{$correct}/{$total}"];
            if ($best === null || $correct > $best[1]) {
                $best = [$t, $correct];
            }
        }

        $this->table(['threshold', 'false refusals', 'false answers', 'correct'], $rows);
        $this->info(sprintf('بهترین آستانه روی این داده: %.2f (%d از %d درست)', $best[0], $best[1], $total));
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