<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('persian-rag requires PostgreSQL with the pgvector extension.');
        }

        // ساختن اکستنشن معمولاً دسترسی superuser می‌خواهد؛ اگر نبود، پیام روشن بده
        if (! DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'")) {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'pgvector is not enabled and this database user cannot create it. '
                    . 'Run once as a superuser (e.g. postgres): CREATE EXTENSION vector;',
                    previous: $e,
                );
            }
        }

        $dimensions = (int) config('persian-rag.embedding.dimensions', 1024);

        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_key')->unique();
            $table->string('title');
            $table->string('audience')->index();
            $table->string('file');
            $table->string('method')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->timestamps();
        });

        Schema::create('rag_chunks', function (Blueprint $table) use ($dimensions) {
            $table->id();
            $table->foreignId('rag_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('page_from');
            $table->unsignedInteger('page_to');
            $table->text('content');                   // متن نرمال‌شده، برای نمایش و LLM
            $table->text('search_text');               // searchForm، برای جست‌وجوی متنی
            $table->string('embedding_model');
            $table->unsignedSmallInteger('embedding_dims');
            $table->vector('embedding', dimensions: $dimensions)->index();   // HNSW با فاصله‌ی cosine
            $table->timestamps();
        });

        DB::statement("ALTER TABLE rag_chunks ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('simple', search_text)) STORED");
        DB::statement('CREATE INDEX rag_chunks_tsv_idx ON rag_chunks USING GIN (tsv)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_chunks');
        Schema::dropIfExists('rag_documents');
    }
};
