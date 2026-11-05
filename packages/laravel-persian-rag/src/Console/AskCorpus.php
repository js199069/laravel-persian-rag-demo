<?php

namespace Js199069\PersianRag\Console;

use Illuminate\Console\Command;
use Js199069\PersianRag\Answering\AnswerGenerator;
use Js199069\PersianRag\Search\HybridSearch;

class AskCorpus extends Command
{
    protected $signature = 'rag:ask
        {question? : سؤال (اگر فارسی درست نرسید، از --file استفاده کن)}
        {--file= : فایل UTF-8 با یک سؤال در هر خط}
        {--role= : نقش کاربر از config/persian-rag.php}
        {--k= : تعداد تکه‌ی منبع (پیش‌فرض از config)}
        {--provider= : provider برای embedding (پیش‌فرض از config)}
        {--llm= : provider مدل پاسخ‌گو (پیش‌فرض از config)}';

    protected $description = 'پاسخ به سؤال بر اساس اسناد، با ارجاع به منبع';

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

        $audiences = null;
        if ($role = $this->option('role')) {
            $audiences = config("persian-rag.roles.{$role}");
            if ($audiences === null) {
                $this->error("نقش «{$role}» در config/persian-rag.php تعریف نشده.");

                return self::FAILURE;
            }
        }

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
        );

        foreach ($questions as $question) {
            $this->newLine();
            $this->info("سؤال: {$question}" . ($audiences ? " [نقش: {$this->option('role')}]" : '') . " [مدل: {$llm}]");

            $r = $generator->answer($question, $audiences);

            $this->line($r['answer']);
            $this->newLine();

            foreach ($r['sources'] as $n => $source) {
                $mark = in_array($n, $r['cited'], true) ? '✓' : ' ';
                $this->line("  {$mark} [{$n}] {$source['doc_key']} — {$source['title']}، {$source['pages']}");
            }

            if ($r['invalid_citations'] !== []) {
                $this->warn('ارجاع به منبع ناموجود: ' . implode('، ', $r['invalid_citations']));
            }
            if ($r['mixed']) {
                $this->warn('جواب مختلط: هم جواب داده و هم «نیامده» گفته.');
            }

            $this->line(sprintf(
                '<comment>شباهت: %.3f | جست‌وجو: %dms | مدل: %dms%s</comment>',
                $r['best_similarity'], $r['search_ms'], $r['llm_ms'],
                $r['refused'] ? ' | امتناع توسط ' . ($r['refused_by'] === 'retrieval' ? 'جست‌وجو' : 'مدل') : '',
            ));
        }

        return self::SUCCESS;
    }
}
