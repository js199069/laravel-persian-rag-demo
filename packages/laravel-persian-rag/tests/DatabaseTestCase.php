<?php

namespace Js199069\PersianRag\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Js199069\PersianRag\Contracts\Embedder;
use Js199069\PersianRag\Models\RagChunk;
use Js199069\PersianRag\Models\RagDocument;
use Js199069\PersianRag\Support\PersianNormalizer;
use Js199069\PersianRag\Tests\Support\FakeEmbedder;
use Throwable;

/**
 * پایه‌ی تست‌هایی که PostgreSQL واقعی با pgvector لازم دارند.
 * فقط روی دیتابیسی با نام *_test اجرا می‌شود، چون جدول‌ها را پاک می‌کند.
 * هر تست داخل یک transaction اجرا و در پایان برگردانده می‌شود.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $migrated = false;

    protected FakeEmbedder $embedder;

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) config('database.connections.pgsql.database');
        if (! str_ends_with($database, '_test')) {
            $this->markTestSkipped("نام دیتابیس تست باید به _test ختم شود (فعلی: {$database})؛ این تست جدول‌ها را پاک می‌کند.");
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('PostgreSQL تست در دسترس نیست: ' . $e->getMessage());
        }

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$migrated = true;
        }

        $this->embedder = new FakeEmbedder();
        $this->app->instance(Embedder::class, $this->embedder);

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->app && DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    protected function createDocument(string $key, string $audience, string $title): RagDocument
    {
        return RagDocument::create([
            'doc_key' => $key,
            'title' => $title,
            'audience' => $audience,
            'file' => "{$key}.pdf",
            'pages' => 1,
        ]);
    }

    /** @param  string|null  $vectorOf  اگر داده شود، embedding از این متن ساخته می‌شود، نه از خود تکه */
    protected function addChunk(RagDocument $document, int $index, string $content, ?string $vectorOf = null): RagChunk
    {
        $text = "{$document->title}\n{$content}";

        return RagChunk::create([
            'rag_document_id' => $document->id,
            'chunk_index' => $index,
            'page_from' => $index + 1,
            'page_to' => $index + 1,
            'content' => $content,
            'search_text' => PersianNormalizer::searchForm($text),
            'embedding_model' => 'fake',
            'embedding_dims' => 1024,
            'embedding' => $this->embedder->vector($vectorOf ?? $text),
        ]);
    }

    /** @param  string[]  $contents */
    protected function document(string $key, string $audience, string $title, array $contents): RagDocument
    {
        $document = $this->createDocument($key, $audience, $title);
        foreach (array_values($contents) as $i => $content) {
            $this->addChunk($document, $i, $content);
        }

        return $document;
    }
}
