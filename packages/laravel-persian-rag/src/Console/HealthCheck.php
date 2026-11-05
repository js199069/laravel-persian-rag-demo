<?php

namespace Js199069\PersianRag\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Js199069\PersianRag\Answering\RagAnswerAgent;
use Js199069\PersianRag\Models\RagChunk;
use Js199069\PersianRag\Models\RagDocument;
use Laravel\Ai\Embeddings;
use Normalizer;
use Throwable;
use ZipArchive;

class HealthCheck extends Command
{
    protected $signature = 'rag:health
        {--llm : یک درخواست واقعی کوچک به مدل پاسخ (هزینه‌ی ناچیز)}';

    protected $description = 'بررسی وابستگی‌ها: دیتابیس، pgvector، embedding، مدل پاسخ، ابزارهای استخراج، نقش‌ها';

    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $this->checkExtensions();
        $tablesReady = $this->checkDatabase();
        $this->checkEmbedding($tablesReady);
        $this->checkAnswerProvider();
        $this->checkTools();
        if ($tablesReady) {
            $this->checkRoles();
        }

        $this->table(['بخش', 'وضعیت', 'جزئیات'], $this->rows);

        if ($this->failed) {
            $this->error('حداقل یک بخش ضروری کار نمی‌کند.');

            return self::FAILURE;
        }

        $this->info('همه‌ی بخش‌های ضروری آماده‌اند.');

        return self::SUCCESS;
    }

    private function add(string $check, string $status, string $details): void
    {
        if ($status === 'fail') {
            $this->failed = true;
        }
        $icon = ['ok' => '✅', 'warn' => '⚠️', 'fail' => '❌'][$status];
        $this->rows[] = [$check, $icon, $details];
    }

    /** پیام همه‌ی لایه‌های خطا؛ خطای واقعی معمولاً پشت پیام کلی پنهان است */
    private function reason(Throwable $e): string
    {
        $messages = [];
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $messages[] = trim($x->getMessage());
        }

        return mb_substr(implode(' ← ', array_unique(array_filter($messages))), 0, 220);
    }

    private function checkExtensions(): void
    {
        class_exists(Normalizer::class)
            ? $this->add('intl', 'ok', 'نرمال‌سازی یونیکد فعال')
            : $this->add('intl', 'fail', 'اکستنشن intl فعال نیست؛ نرمال‌سازی فارسی کار نمی‌کند');

        class_exists(ZipArchive::class)
            ? $this->add('zip', 'ok', 'خواندن DOCX فعال')
            : $this->add('zip', 'warn', 'اکستنشن zip فعال نیست؛ DOCXها خوانده نمی‌شوند');
    }

    private function checkDatabase(): bool
    {
        try {
            if (DB::getDriverName() !== 'pgsql') {
                $this->add('دیتابیس', 'fail', 'PostgreSQL لازم است؛ درایور فعلی: ' . DB::getDriverName());

                return false;
            }
            DB::select('SELECT 1');
            $this->add('دیتابیس', 'ok', 'PostgreSQL، اتصال برقرار');
        } catch (Throwable $e) {
            $this->add('دیتابیس', 'fail', $this->reason($e));

            return false;
        }

        try {
            $vector = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
            $vector
                ? $this->add('pgvector', 'ok', "نسخه‌ی {$vector->extversion}")
                : $this->add('pgvector', 'fail', 'فعال نیست؛ یک بار با کاربر superuser: CREATE EXTENSION vector;');
        } catch (Throwable $e) {
            $this->add('pgvector', 'fail', $this->reason($e));
        }

        if (! Schema::hasTable('rag_documents') || ! Schema::hasTable('rag_chunks')) {
            $this->add('جدول‌ها', 'fail', 'ساخته نشده‌اند؛ php artisan migrate');

            return false;
        }

        $documents = RagDocument::count();
        $chunks = RagChunk::count();
        $chunks === 0
            ? $this->add('ایندکس', 'warn', 'خالی است؛ rag:extract، rag:refine و rag:index را اجرا کن')
            : $this->add('ایندکس', 'ok', "{$documents} سند، {$chunks} تکه");

        return true;
    }

    private function checkEmbedding(bool $tablesReady): void
    {
        $provider = config('persian-rag.embedding.provider');
        $expected = (int) config('persian-rag.embedding.dimensions');

        try {
            $start = microtime(true);
            $vector = Embeddings::for(['آزمون سلامت'])->generate($provider)->embeddings[0];
            $ms = (int) ((microtime(true) - $start) * 1000);

            count($vector) === $expected
                ? $this->add('embedding', 'ok', "{$provider}، " . count($vector) . " بعد، {$ms}ms")
                : $this->add('embedding', 'fail', count($vector) . " بعد، ولی config و ستون دیتابیس {$expected} بعد می‌خواهند");
        } catch (Throwable $e) {
            $hint = str_contains($provider, 'ollama') ? ' (Ollama روشن است؟ ollama ps)' : '';
            $this->add('embedding', 'fail', "{$provider}: " . $this->reason($e) . $hint);

            return;
        }

        if (! $tablesReady || RagChunk::count() === 0) {
            return;
        }

        $current = config("ai.providers.{$provider}.models.embeddings.default");
        $indexed = RagChunk::query()->distinct()->pluck('embedding_model')->all();

        if (count($indexed) > 1) {
            $this->add('مدل ایندکس', 'warn', 'ایندکس با چند مدل ساخته شده: ' . implode('، ', $indexed) . '؛ rag:index --fresh');
        } elseif ($current && $indexed !== [] && $indexed[0] !== $current) {
            $this->add('مدل ایندکس', 'fail', "ایندکس با {$indexed[0]} ساخته شده، ولی مدل فعلی {$current} است؛ rag:index --fresh");
        } else {
            $this->add('مدل ایندکس', 'ok', $indexed[0] ?? '-');
        }
    }

    private function checkAnswerProvider(): void
    {
        $provider = config('persian-rag.answer.provider');

        if (! config("ai.providers.{$provider}")) {
            $this->add('مدل پاسخ', 'fail', "provider «{$provider}» در config/ai.php تعریف نشده (RAG_LLM_PROVIDER)");

            return;
        }

        if (! $this->option('llm')) {
            $this->add('مدل پاسخ', 'ok', "{$provider} تعریف شده (برای تست واقعی: --llm)");

            return;
        }

        try {
            $start = microtime(true);
            $text = trim((string) (new RagAnswerAgent('فقط بنویس: OK'))->prompt('آزمون', provider: $provider, timeout: 60));
            $ms = (int) ((microtime(true) - $start) * 1000);
            $this->add('مدل پاسخ', 'ok', "{$provider} پاسخ داد ({$ms}ms): " . mb_substr($text, 0, 30));
        } catch (Throwable $e) {
            $this->add('مدل پاسخ', 'fail', "{$provider}: " . $this->reason($e));
        }
    }

    /** ابزارهای استخراج فقط برای ساختن ایندکس لازم‌اند، پس نبودشان هشدار است، نه خطا */
    private function checkTools(): void
    {
        $tools = [
            'pdftotext' => [config('persian-rag.extraction.pdftotext'), ['-v']],
            'pdftoppm' => [config('persian-rag.extraction.pdftoppm'), ['-v']],
            'tesseract' => [config('persian-rag.extraction.tesseract'), ['--list-langs']],
        ];
        $language = config('persian-rag.extraction.ocr_language', 'fas');

        foreach ($tools as $name => [$binary, $args]) {
            try {
                $result = Process::timeout(20)->run([$binary, ...$args]);
                $output = strtolower($result->output() . $result->errorOutput());
                $found = $result->successful() || str_contains($output, $name);
            } catch (Throwable) {
                $output = '';
                $found = false;
            }

            if (! $found) {
                $this->add($name, 'warn', "پیدا نشد ({$binary})؛ برای استخراج لازم است");
            } elseif ($name === 'tesseract' && ! str_contains($output, $language)) {
                $this->add($name, 'warn', "مدل زبان «{$language}» نصب نیست");
            } else {
                $this->add($name, 'ok', $binary);
            }
        }
    }

    private function checkRoles(): void
    {
        $known = collect(config('persian-rag.roles', []))->flatten()->unique()->all();
        $used = RagDocument::query()->distinct()->pluck('audience')->all();
        $orphans = array_values(array_diff($used, $known));

        $orphans === []
            ? $this->add('نقش‌ها', 'ok', count(config('persian-rag.roles', [])) . ' نقش؛ همه‌ی برچسب‌های مخاطب پوشش داده شده‌اند')
            : $this->add('نقش‌ها', 'warn', 'اسناد با این برچسب‌ها برای هیچ نقشی دیده نمی‌شوند: ' . implode('، ', $orphans));
    }
}
