<?php

namespace Js199069\PersianRag\Console;

use Js199069\PersianRag\Search\HybridSearch;
use Illuminate\Console\Command;

class SearchCorpus extends Command
{
    protected $signature = 'rag:search
        {question? : سؤال (اگر فارسی درست نرسید، از --file استفاده کن)}
        {--file= : فایل UTF-8 با یک سؤال در هر خط}
        {--audience=* : فیلتر مخاطب، مثلاً --audience=phd}
	{--role= : نقش کاربر از config/rag.php، مثلاً bachelor}
        {--k=5 : تعداد نتیجه}
        {--provider=ollama_local}';

    protected $description = 'تست جست‌وجوی ترکیبی (برداری + متنی) روی ایندکس';

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

        $audiences = $this->option('audience') ?: null;

if ($role = $this->option('role')) {
    $audiences = config("persian-rag.roles.{$role}");
    if ($audiences === null) {
        $this->error("نقش «{$role}» در config/rag.php تعریف نشده.");

        return self::FAILURE;
    }
}
        $search = new HybridSearch($this->option('provider'));

        foreach ($questions as $question) {
            $this->newLine();
            $this->info("سؤال: {$question}" . ($audiences ? ' [مخاطب: ' . implode('، ', $audiences) . ']' : ''));

            $start = microtime(true);
            $result = $search->search($question, $audiences, (int) $this->option('k'));
            $ms = (int) ((microtime(true) - $start) * 1000);

            $this->line(sprintf('بیشترین شباهت برداری: %.3f | زمان: %dms', $result['best_similarity'], $ms));
		$this->line('کلمه‌های جست‌وجوی متنی: ' . ($result['text_query'] ?: '-'));

            if ($result['results'] === []) {
                $this->warn('نتیجه‌ای پیدا نشد.');
                continue;
            }

            foreach ($result['results'] as $i => $r) {
                $chunk = $r['chunk'];
                $pages = $chunk->page_from === $chunk->page_to
                    ? "ص {$chunk->page_from}" : "ص {$chunk->page_from}-{$chunk->page_to}";
                $sources = ($r['in_vector'] ? 'V' : '-') . ($r['in_text'] ? 'T' : '-');

                $this->line(sprintf(
                    '<comment>%d. %s</comment> (%s، %s) [%s] شباهت: %s | %s',
                    $i + 1, $chunk->document->doc_key, $chunk->document->audience, $pages, $sources,
                    $r['similarity'] !== null ? sprintf('%.3f', $r['similarity']) : '-',
                    mb_substr($chunk->document->title, 0, 35),
                ));
                $this->line('   ' . mb_substr(preg_replace('/\s+/u', ' ', $chunk->content), 0, 160));
            }
        }

        return self::SUCCESS;
    }
}