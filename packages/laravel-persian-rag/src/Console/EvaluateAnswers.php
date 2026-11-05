<?php

namespace Js199069\PersianRag\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Js199069\PersianRag\Answering\AnswerGenerator;
use Js199069\PersianRag\Search\HybridSearch;
use Js199069\PersianRag\Support\Manifest;
use Js199069\PersianRag\Support\PersianNormalizer;
use Throwable;

class EvaluateAnswers extends Command
{
    protected $signature = 'rag:eval-answers
        {--file=storage/app/eval.csv : فایل سؤال‌های ارزیابی}
        {--ids= : فقط این شناسه‌ها، مثلاً 1,2,16}
        {--k= : تعداد تکه‌ی منبع (پیش‌فرض از config)}
        {--provider= : provider برای embedding (پیش‌فرض از config)}
        {--llm= : provider مدل پاسخ‌گو (پیش‌فرض از config)}';

    protected $description = 'ارزیابی در سطح پاسخ: درستی، امتناع در سؤال بی‌جواب، و اعتبار ارجاع‌ها';

    public function handle(): int
    {
        $file = $this->option('file');
        $path = is_file($file) ? $file : base_path($file);
        if (! is_file($path)) {
            $this->error("فایل ارزیابی پیدا نشد: {$path}");

            return self::FAILURE;
        }

        $ids = $this->option('ids') ? array_map('trim', explode(',', $this->option('ids'))) : null;
        $provider = $this->option('provider') ?: config('persian-rag.embedding.provider');
        $llm = $this->option('llm') ?: config('persian-rag.answer.provider');

        $generator = new AnswerGenerator(
            search: new HybridSearch(
                provider: $provider,
                candidates: (int) config('persian-rag.search.candidates'),
                maxPerDocument: (int) config('persian-rag.search.max_per_document'),
                maxTermShare: (float) config('persian-rag.search.max_term_share'),
                rrfK: (int) config('persian-rag.search.rrf_k'),
                questionWords: config('persian-rag.search.question_words'),
            ),
            llmProvider: $llm,
            k: (int) ($this->option('k') ?: config('persian-rag.answer.k')),
            minSimilarity: (float) config('persian-rag.min_similarity'),
            timeout: (int) config('persian-rag.answer.timeout'),
            refusal: config('persian-rag.answer.refusal'),
            retries: (int) config('persian-rag.answer.retries', 2),
        );

        $this->info("مدل پاسخ‌گو: {$llm}");

        // نتیجه بعد از هر سؤال ذخیره می‌شود؛ قطع شدن وسط کار، کار انجام‌شده را از بین نمی‌برد
        $out = 'eval-answers/' . now()->format('Ymd-His') . '-' . $llm . '.json';

        $table = [];
        $details = [];
        $errors = [];
        $stats = [
            'answerable' => 0, 'false_refusals' => 0, 'mixed_answers' => 0,
            'keyword_total' => 0, 'keyword_correct' => 0,
            'citation_total' => 0, 'citation_ok' => 0, 'invalid_citations' => 0,
            'negative' => 0, 'refused_retrieval' => 0, 'refused_llm' => 0, 'mixed_negatives' => 0,
            'access' => 0, 'leaks' => 0,
            'llm_ms' => [],
        ];

        foreach (Manifest::read($path) as $row) {
            if ($ids !== null && ! in_array($row['id'], $ids, true)) {
                continue;
            }

            $role = trim($row['role'] ?? '');
            $audiences = $role !== '' ? config("persian-rag.roles.{$role}") : null;
            $expectedDocs = array_values(array_filter(array_map('trim', explode('|', $row['expected_doc'] ?? ''))));
            $keywords = array_values(array_filter(array_map('trim', explode('|', $row['expected_answer'] ?? ''))));
            $mustNotDoc = trim($row['must_not_doc'] ?? '');
            $type = $mustNotDoc !== '' ? 'access' : ($expectedDocs === [] ? 'negative' : 'answer');

            $this->line("سؤال {$row['id']} ...");

            try {
                $r = $generator->answer($row['question'], $audiences);
            } catch (Throwable $e) {
                // خطای یک سؤال (مثلاً قطع سرویس بعد از همه‌ی تلاش‌ها) ثبت می‌شود و ارزیابی ادامه پیدا می‌کند
                $errors[] = $row['id'];
                $table[] = [$row['id'], $type, '⚠️ خطا', '-', '-', mb_substr($e->getMessage(), 0, 70)];
                $details[] = ['id' => $row['id'], 'question' => $row['question'], 'role' => $role, 'error' => $e->getMessage()];
                Storage::put($out, json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                continue;
            }

            if ($r['llm_ms'] > 0) {
                $stats['llm_ms'][] = $r['llm_ms'];
            }
            $stats['invalid_citations'] += count($r['invalid_citations']);
            $sourceDocs = array_column($r['sources'], 'doc_key');
            $excerpt = mb_substr(preg_replace('/\s+/u', ' ', $r['answer']), 0, 70);
            $refusedLabel = $r['refused'] ? ($r['refused_by'] === 'retrieval' ? 'جست‌وجو' : 'مدل') : '-';

            if ($type === 'access') {
                $stats['access']++;
                $leak = in_array($mustNotDoc, $sourceDocs, true);
                $stats['leaks'] += $leak ? 1 : 0;
                $table[] = [$row['id'], 'access', $refusedLabel, '-', $leak ? '❌ نشت' : '✅', $excerpt];
            } elseif ($type === 'negative') {
                // سؤال بی‌جواب: فقط امتناع خالص درست است
                $stats['negative']++;
                if ($r['refused']) {
                    $stats[$r['refused_by'] === 'retrieval' ? 'refused_retrieval' : 'refused_llm']++;
                } elseif ($r['mixed']) {
                    $stats['mixed_negatives']++;
                }
                $label = $r['refused'] ? "✅ {$refusedLabel}" : ($r['mixed'] ? '❌ مختلط' : '❌ جواب داد');
                $table[] = [$row['id'], 'negative', $label, '-', '-', $excerpt];
            } else {
                $stats['answerable']++;
                $stats['false_refusals'] += $r['refused'] ? 1 : 0;
                $stats['mixed_answers'] += $r['mixed'] ? 1 : 0;

                $correct = '-';
                if ($keywords !== []) {
                    $stats['keyword_total']++;
                    $answerForm = PersianNormalizer::searchForm($r['answer']);
                    $hit = ! $r['refused'] && collect($keywords)->contains(
                        fn ($k) => str_contains($answerForm, PersianNormalizer::searchForm($k)),
                    );
                    $stats['keyword_correct'] += $hit ? 1 : 0;
                    $correct = $hit ? '✅' : '❌';
                }

                $citation = '-';
                if (! $r['refused']) {
                    $stats['citation_total']++;
                    $citedDocs = array_map(fn ($n) => $r['sources'][$n]['doc_key'] ?? null, $r['cited']);
                    $ok = array_intersect($citedDocs, $expectedDocs) !== [];
                    $stats['citation_ok'] += $ok ? 1 : 0;
                    $citation = $ok ? '✅' : '❌';
                }

                $label = $r['refused'] ? "❌ امتناع ({$refusedLabel})" : ($r['mixed'] ? '⚠️ مختلط' : '✅');
                $table[] = [$row['id'], 'answer', $label, $correct, $citation, $excerpt];
            }

            $details[] = ['id' => $row['id'], 'question' => $row['question'], 'role' => $role] + $r;
            Storage::put($out, json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $this->table(['id', 'type', 'answered/refused', 'keyword', 'citation', 'answer'], $table);

        if ($stats['answerable'] > 0) {
            $this->info(sprintf(
                'جواب‌دار (%d): امتناع اشتباه %d | مختلط %d | کلمه‌ی کلیدی درست %d از %d | ارجاع به سند درست %d از %d',
                $stats['answerable'], $stats['false_refusals'], $stats['mixed_answers'],
                $stats['keyword_correct'], $stats['keyword_total'],
                $stats['citation_ok'], $stats['citation_total'],
            ));
        }
        if ($stats['negative'] > 0) {
            $this->info(sprintf(
                'بی‌جواب (%d): امتناع درست %d (جست‌وجو %d، مدل %d) | مختلط %d',
                $stats['negative'], $stats['refused_retrieval'] + $stats['refused_llm'],
                $stats['refused_retrieval'], $stats['refused_llm'], $stats['mixed_negatives'],
            ));
        }
        if ($stats['access'] > 0) {
            $this->info("دسترسی ({$stats['access']}): {$stats['leaks']} نشت");
        }
        $this->info("ارجاع به منبع ناموجود: {$stats['invalid_citations']}");
        if ($stats['llm_ms'] !== []) {
            $this->info(sprintf('میانگین زمان مدل: %.1f ثانیه', array_sum($stats['llm_ms']) / count($stats['llm_ms']) / 1000));
        }
        $this->info("پاسخ‌های کامل ذخیره شد: storage/app/{$out}");

        if ($errors !== []) {
            $this->warn(sprintf(
                'خطا در %d سؤال (در عددهای بالا حساب نشده‌اند): %s — دوباره: --ids=%s',
                count($errors), implode('، ', $errors), implode(',', $errors),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
