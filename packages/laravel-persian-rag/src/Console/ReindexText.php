<?php

namespace Js199069\PersianRag\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Js199069\PersianRag\Indexing\ChunkContext;
use Js199069\PersianRag\Models\RagChunk;
use Js199069\PersianRag\Models\RagDocument;
use Js199069\PersianRag\Support\PersianNormalizer;

class ReindexText extends Command
{
    protected $signature = 'rag:reindex-text';

    protected $description = 'بازسازی متن جست‌وجو (search_text) همه‌ی تکه‌ها با نرمال‌سازی فعلی، بدون embedding دوباره';

    public function handle(): int
    {
        $labels = config('persian-rag.audience_labels', []);
        $checked = 0;
        $changed = 0;
        $start = microtime(true);

        foreach (RagDocument::query()->orderBy('id')->get() as $document) {
            $context = ChunkContext::text($document->title, $document->audience, $labels);

            DB::transaction(function () use ($document, $context, &$checked, &$changed) {
                $chunks = RagChunk::query()
                    ->where('rag_document_id', $document->id)
                    ->select(['id', 'content', 'search_text'])
                    ->get();

                foreach ($chunks as $chunk) {
                    $checked++;
                    $text = PersianNormalizer::searchForm("{$context}\n{$chunk->content}");
                    if ($text !== $chunk->search_text) {
                        RagChunk::whereKey($chunk->id)->update(['search_text' => $text]);
                        $changed++;
                    }
                }
            });
        }

        $this->info(sprintf(
            '%d تکه بررسی شد، %d تکه به‌روز شد (%dms). embeddingها دست نخوردند.',
            $checked, $changed, (int) ((microtime(true) - $start) * 1000),
        ));

        return self::SUCCESS;
    }
}
