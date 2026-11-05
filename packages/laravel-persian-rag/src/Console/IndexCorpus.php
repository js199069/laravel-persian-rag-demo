<?php

namespace Js199069\PersianRag\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Js199069\PersianRag\Indexing\ChunkContext;
use Js199069\PersianRag\Indexing\Chunker;
use Js199069\PersianRag\Models\RagChunk;
use Js199069\PersianRag\Models\RagDocument;
use Js199069\PersianRag\Support\Manifest;
use Js199069\PersianRag\Support\PersianNormalizer;
use Laravel\Ai\Embeddings;
use RuntimeException;

class IndexCorpus extends Command
{
    protected $signature = 'rag:index
        {--provider= : provider برای embedding (پیش‌فرض از config)}
        {--batch=4 : تعداد تکه در هر درخواست embedding}
        {--fresh : پاک کردن کل ایندکس و ساخت از اول}
        {--max-chars=1000 : حداکثر طول هر تکه}
        {--overlap=250 : حداکثر طول جمله‌ی هم‌پوشان}';

    protected $description = 'تکه‌تکه کردن متن اسناد، ساخت embedding و ذخیره در PostgreSQL';

    public function handle(): int
    {
        $provider = $this->option('provider') ?: config('persian-rag.embedding.provider');
        $model = config("ai.providers.{$provider}.models.embeddings.default") ?? config('persian-rag.embedding.model', 'unknown');
        $dimensions = (int) config('persian-rag.embedding.dimensions');
        $labels = config('persian-rag.audience_labels', []);
        $corpus = rtrim(config('persian-rag.corpus_path'), '/\\');

        $chunker = new Chunker((int) $this->option('max-chars'), (int) $this->option('overlap'));
        $batch = max(1, (int) $this->option('batch'));
        $manifest = Manifest::keyed($corpus . '/manifest.csv');

        $this->warnUnknownAudiences($manifest);

        if ($this->option('fresh')) {
            RagChunk::query()->delete();
            RagDocument::query()->delete();
            $this->warn('ایندکس قبلی پاک شد.');
        }

        $files = glob($corpus . '/text/*.json') ?: [];
        $totalChunks = 0;
        $totalChars = 0;
        $start = microtime(true);

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $row = $manifest[$data['id']] ?? [];

            // manifest.csv منبع اصلی برچسب‌هاست؛ ستون title اختیاری است
            $title = trim($row['title'] ?? '') !== ''
                ? PersianNormalizer::normalize(trim($row['title']))
                : $this->cleanTitle($data['original_name'] ?? $data['id']);
            $audience = trim($row['audience'] ?? '') ?: ($data['audience'] ?: 'all_students');

            $document = RagDocument::updateOrCreate(
                ['doc_key' => $data['id']],
                [
                    'title' => $title,
                    'audience' => $audience,
                    'file' => $data['file'],
                    'method' => $data['method'] ?? null,
                    'pages' => count($data['pages']),
                ],
            );

            if ($document->chunks()->exists()) {
                $this->line("{$data['id']}: قبلاً ایندکس شده؛ فقط متادیتا به‌روز شد (برای ساخت دوباره --fresh)");
                continue;
            }

            $docStart = microtime(true);
            $chunks = $chunker->chunk($data['pages']);

            // زمینه‌ی هر تکه: عنوان سند و مخاطب آن
            $context = ChunkContext::text($title, $audience, $labels);

            $vectors = [];
            foreach (array_chunk($chunks, $batch) as $group) {
                $inputs = array_map(fn ($c) => PersianNormalizer::canonical("{$context}\n{$c['content']}"), $group);
                array_push($vectors, ...Embeddings::for($inputs)->generate($provider)->embeddings);
            }

            DB::transaction(function () use ($chunks, $vectors, $document, $context, $model, $dimensions) {
                foreach ($chunks as $i => $chunk) {
                    if (count($vectors[$i]) !== $dimensions) {
                        throw new RuntimeException('ابعاد embedding ' . count($vectors[$i]) . " است، نه {$dimensions}؛ مدل embedding با ستون دیتابیس هماهنگ نیست.");
                    }

                    RagChunk::create([
                        'rag_document_id' => $document->id,
                        'chunk_index' => $i,
                        'page_from' => $chunk['page_from'],
                        'page_to' => $chunk['page_to'],
                        'content' => $chunk['content'],
                        'search_text' => PersianNormalizer::searchForm("{$context}\n{$chunk['content']}"),
                        'embedding_model' => $model,
                        'embedding_dims' => count($vectors[$i]),
                        'embedding' => $vectors[$i],
                    ]);
                }
            });

            $chars = array_sum(array_map(fn ($c) => mb_strlen($c['content']), $chunks));
            $totalChunks += count($chunks);
            $totalChars += $chars;

            $this->line(sprintf(
                '%s: %d تکه، میانگین %d کاراکتر، %dms — %s',
                $data['id'], count($chunks), count($chunks) ? intdiv($chars, count($chunks)) : 0,
                (int) ((microtime(true) - $docStart) * 1000), mb_substr($title, 0, 40),
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            'تمام شد: %d تکه، میانگین %d کاراکتر، کل زمان %d ثانیه (مدل: %s)',
            $totalChunks, $totalChunks ? intdiv($totalChars, $totalChunks) : 0,
            (int) (microtime(true) - $start), $model,
        ));

        return self::SUCCESS;
    }

    /** برچسبی که در هیچ نقشی نیست یعنی سندی که هیچ‌کس نمی‌بیند؛ این خطا هیچ‌جای دیگری دیده نمی‌شود */
    private function warnUnknownAudiences(array $manifest): void
    {
        $known = collect(config('persian-rag.roles', []))->flatten()->unique()->all();

        foreach ($manifest as $id => $row) {
            $audience = trim($row['audience'] ?? '');
            if ($audience !== '' && ! in_array($audience, $known, true)) {
                $this->warn("{$id}: برچسب مخاطب «{$audience}» در هیچ نقشی نیست؛ این سند برای هیچ‌کس دیده نمی‌شود.");
            }
        }
    }

    private function cleanTitle(string $name): string
    {
        $title = str_replace('_', ' ', $name);
        $title = preg_replace('/[()]+\s*$/u', '', $title);

        return PersianNormalizer::normalize(trim($title));
    }
}
