<?php

namespace Js199069\PersianRag\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Js199069\PersianRag\Tests\DatabaseTestCase;

final class ReindexTextTest extends DatabaseTestCase
{
    private function searchTextMatches(int $chunkId, string $term): bool
    {
        return DB::table('rag_chunks')
            ->where('id', $chunkId)
            ->whereRaw("tsv @@ to_tsquery('simple', ?)", [$term])
            ->exists();
    }

    public function test_rebuilds_search_text_without_touching_embeddings(): void
    {
        $document = $this->createDocument('doc-faculty', 'faculty', 'آیین‌نامه تخلفات');
        $chunk = $this->addChunk($document, 0, 'رسیدگی به تخلفات اعضای هیئت علمی در هیئت بدوی انجام می‌شود.');

        // شبیه‌سازی ایندکسی که قبل از یکسان‌سازی املاها ساخته شده: «هیئت» به جای «هیات»
        DB::table('rag_chunks')->where('id', $chunk->id)->update(['search_text' => 'رسیدگی به تخلفات اعضای هیئت علمی']);
        $embeddingBefore = DB::table('rag_chunks')->where('id', $chunk->id)->value('embedding');

        $this->assertFalse($this->searchTextMatches($chunk->id, 'هیات'), 'قبل از بازسازی، شکل یکسان‌شده پیدا نمی‌شود');

        Artisan::call('rag:reindex-text');

        $this->assertTrue($this->searchTextMatches($chunk->id, 'هیات'), 'بعد از بازسازی، شکل یکسان‌شده پیدا می‌شود');
        $this->assertSame($embeddingBefore, DB::table('rag_chunks')->where('id', $chunk->id)->value('embedding'));
    }
}