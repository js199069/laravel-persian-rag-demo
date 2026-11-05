<?php

namespace Js199069\PersianRag;

use Illuminate\Support\ServiceProvider;
use Js199069\PersianRag\Answering\AnswerGenerator;
use Js199069\PersianRag\Contracts\Embedder;
use Js199069\PersianRag\Search\HybridSearch;
use Js199069\PersianRag\Search\LaravelAiEmbedder;

class PersianRagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/persian-rag.php', 'persian-rag');

        $this->app->singleton(Embedder::class, LaravelAiEmbedder::class);

        $this->app->singleton(HybridSearch::class, fn ($app) => new HybridSearch(
            provider: config('persian-rag.embedding.provider'),
            candidates: (int) config('persian-rag.search.candidates'),
            maxPerDocument: (int) config('persian-rag.search.max_per_document'),
            maxTermShare: (float) config('persian-rag.search.max_term_share'),
            rrfK: (int) config('persian-rag.search.rrf_k'),
            questionWords: config('persian-rag.search.question_words'),
            embedder: $app->make(Embedder::class),
        ));

        $this->app->bind(AnswerGenerator::class, fn ($app) => new AnswerGenerator(
            search: $app->make(HybridSearch::class),
            llmProvider: config('persian-rag.answer.provider'),
            k: (int) config('persian-rag.answer.k'),
            minSimilarity: (float) config('persian-rag.min_similarity'),
            timeout: (int) config('persian-rag.answer.timeout'),
            refusal: config('persian-rag.answer.refusal'),
        ));

        $this->app->bind(Rag::class, fn ($app) => new Rag(
            search: $app->make(HybridSearch::class),
            generator: $app->make(AnswerGenerator::class),
            roles: config('persian-rag.roles', []),
            llmProvider: config('persian-rag.answer.provider'),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\ExtractCorpus::class,
                Console\CorpusQuality::class,
                Console\RefineCorpus::class,
                Console\IndexCorpus::class,
                Console\SearchCorpus::class,
                Console\EvaluateSearch::class,
                Console\AskCorpus::class,
                Console\EvaluateAnswers::class,
                Console\HealthCheck::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/persian-rag.php' => config_path('persian-rag.php'),
            ], ['persian-rag', 'persian-rag-config']);

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'persian-rag-migrations');
        }
    }
}
