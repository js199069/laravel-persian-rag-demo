<?php

namespace Js199069\PersianRag\Answering;

use Js199069\PersianRag\Search\HybridSearch;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Throwable;

final class AnswerGenerator
{
    public function __construct(
        private HybridSearch $search,          // embedding همیشه با مدل محلیِ ایندکس
        private string $llmProvider = 'ollama_local',
        private int $k = 3,
        private float $minSimilarity = 0.55,
        private int $timeout = 300,
        private string $refusal = 'پاسخ این سؤال در اسناد موجود نیامده است.',
        private int $retries = 2,
    ) {}

    /**
     * جست‌وجو + ساختن منبع‌ها و پرامپت؛ مشترک بین خط فرمان و وب.
     *
     * @param  string[]|null  $audiences
     */
    public function prepare(string $question, ?array $audiences = null): array
    {
        $refusal = $this->refusal;

        $start = microtime(true);
        $found = $this->search->search($question, $audiences, $this->k);

        $prepared = [
            'refusal' => $refusal,
            'best_similarity' => $found['best_similarity'],
            'search_ms' => (int) ((microtime(true) - $start) * 1000),
            'sources' => [],
            'instructions' => '',
            'prompt' => '',
            'refused' => false,
        ];

        // لایه‌ی ۱: سؤال کاملاً بی‌ربط
        if ($found['results'] === [] || $found['best_similarity'] < $this->minSimilarity) {
            $prepared['refused'] = true;

            return $prepared;
        }

        $context = '';
        foreach ($found['results'] as $i => $hit) {
            $n = $i + 1;
            $chunk = $hit['chunk'];
            $pages = $chunk->page_from === $chunk->page_to
                ? "صفحه‌ی {$chunk->page_from}"
                : "صفحه‌های {$chunk->page_from} تا {$chunk->page_to}";

            $prepared['sources'][$n] = [
                'doc_key' => $chunk->document->doc_key,
                'title' => $chunk->document->title,
                'audience' => $chunk->document->audience,
                'pages' => $pages,
                'snippet' => mb_substr(preg_replace('/\s+/u', ' ', $chunk->content), 0, 350),
            ];
            $context .= "[{$n}] {$chunk->document->title}، {$pages}\n{$chunk->content}\n\n";
        }

        $prepared['instructions'] = implode("\n", [
            'تو فقط از روی منبع‌های داده‌شده به سؤال جواب می‌دهی.',
            'مرحله ۱: در منبع‌ها دنبال جمله‌ای بگرد که مستقیماً به سؤال جواب می‌دهد.',
            'مرحله ۲: اگر پیدا کردی، جواب را در یک یا دو جمله‌ی کوتاه بنویس و شماره‌ی منبع را آخرش بگذار، مثل [1].',
            'اگر منبع مخصوص گروه یا رشته‌ی خاصی است (مثلاً «علوم پزشکی»)، این را در جواب بگو.',
            "مرحله ۳: اگر هیچ منبعی جواب سؤال را ندارد، فقط بنویس: {$refusal}",
            'هرگز جواب و جمله‌ی «نیامده» را با هم ننویس. فقط به فارسی جواب بده.',
        ]);
        $prepared['prompt'] = "سؤال: {$question}\n\nمنبع‌ها:\n{$context}سؤال: {$question}\nپاسخ:";

        return $prepared;
    }

    /** @param  string[]|null  $audiences */
    public function answer(string $question, ?array $audiences = null): array
    {
        $p = $this->prepare($question, $audiences);

        $result = [
            'best_similarity' => $p['best_similarity'],
            'search_ms' => $p['search_ms'],
            'llm_ms' => 0,
            'llm_provider' => $this->llmProvider,
            'sources' => [],
            'cited' => [],
            'invalid_citations' => [],
            'mixed' => false,
        ];

        if ($p['refused']) {
            return $result + ['answer' => $p['refusal'], 'refused' => true, 'refused_by' => 'retrieval'];
        }

        $llmStart = microtime(true);
                        // خطای موقت سرویس (شلوغی ۵۰۲/۵۰۳ یا قطع اتصال): دوباره تلاش، با فاصله‌ی ۳ و ۶ ثانیه
                $text = trim((string) retry(
                    $this->retries + 1,
                    fn () => (new RagAnswerAgent($p['instructions']))->prompt(
                        $p['prompt'],
                        provider: $this->llmProvider,
                        timeout: $this->timeout,
                    ),
                    fn (int $attempt) => $attempt * 3000,
                    fn (Throwable $e) => $e instanceof ProviderOverloadedException
                    || $e instanceof ProviderConnectionException,
                ));
        $result['llm_ms'] = (int) ((microtime(true) - $llmStart) * 1000);

        $parsed = AnswerParser::parse($text, $p['refusal'], array_keys($p['sources']));

        $result['sources'] = $p['sources'];
        $result['cited'] = $parsed['cited'];
        $result['invalid_citations'] = $parsed['invalid_citations'];
        $result['mixed'] = $parsed['mixed'];

        return $result + ['answer' => $text, 'refused' => $parsed['refused'], 'refused_by' => $parsed['refused'] ? 'llm' : null];
    }
}
