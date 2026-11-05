<?php

namespace App\Console\Commands;

use App\Ai\Tools\GetOrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Throwable;

use function Laravel\Ai\agent;

class AiMatrix extends Command
{
    protected $signature = 'ai:matrix
        {providers* : نام providerها در config/ai.php}
        {--timeout=300 : حداکثر زمان هر درخواست به ثانیه}';

    protected $description = 'تست چت، استریم، embedding و tool calling فارسی روی هر provider';

    private const INSTRUCTIONS = 'تو یک دستیار فنی هستی. همیشه و فقط به فارسی پاسخ بده.';
    private const CONTEXT = 'طبق آیین‌نامه‌ی داخلی شرکت، کارمندان پس از شش ماه سابقه می‌توانند تا ده روز مرخصی استحقاقی در سال بگیرند. درخواست مرخصی باید دست‌کم سه روز کاری قبل از تاریخ شروع، از طریق سامانه‌ی پرسنلی ثبت شود.';
    public function handle(): int
    {
        $results = [];

        foreach ($this->argument('providers') as $provider) {
            $this->info("در حال تست {$provider} ...");

            $results[$provider] = [
                'chat'      => $this->measure(fn () => $this->testChat($provider)),
                'stream'    => $this->measure(fn () => $this->testStream($provider)),
                'embedding' => $this->measure(fn () => $this->testEmbedding($provider)),
                'tools'     => $this->measure(fn () => $this->testTools($provider)),
                'grounded'  => $this->measure(fn () => $this->testGrounded($provider)),
                'refusal'   => $this->measure(fn () => $this->testRefusal($provider)),
            ];
        }

        $rows = [];
        foreach ($results as $provider => $tests) {
            $rows[] = [$provider, ...array_values(array_map(fn ($t) => $t['label'], $tests))];
        }
        $this->table(['Provider', 'Chat', 'Streaming', 'Embedding', 'Tool calling', 'Grounded', 'Refusal'], $rows);

        foreach ($results as $provider => $tests) {
            foreach ($tests as $name => $t) {
                $this->line("<comment>{$provider}.{$name}</comment>: {$t['note']}");
            }
        }

        $path = 'ai-matrix/' . now()->format('Ymd-His') . '.json';
        Storage::put($path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("نتیجه ذخیره شد: storage/app/{$path}");

        return self::SUCCESS;
    }

    private function timeout(): int
    {
        return (int) $this->option('timeout');
    }

    private function testChat(string $provider): array
    {
        $text = (string) agent(instructions: self::INSTRUCTIONS)
            ->prompt('در دو جمله توضیح بده «کش» در برنامه‌نویسی چیست.', provider: $provider, timeout: $this->timeout());

        return [$this->isPersian($text), mb_substr($text, 0, 80)];
    }

    private function testStream(string $provider): array
    {
        $start = microtime(true);
        $first = null;
        $events = 0;

        $stream = agent(instructions: self::INSTRUCTIONS)
            ->stream('سه مزیت کش را فهرست کن.', provider: $provider);

        foreach ($stream as $event) {
            $first ??= microtime(true);
            $events++;
        }

        $ttfe = $first ? (int) (($first - $start) * 1000) : null;

        return [$events > 1, "{$events} رویداد؛ اولین رویداد بعد از {$ttfe}ms"];
    }

    private function testEmbedding(string $provider): array
    {
        $texts = [
            'قرارداد کاری چه زمانی تمدید می‌شود؟',
            'مهلت تمدید قرارداد کارمندان کی است؟',   // هم‌معنی، با کلمات متفاوت
            'دستور پخت قورمه‌سبزی چیست؟',            // نامرتبط
        ];

        $v = Embeddings::for($texts)->generate($provider)->embeddings;

        $similar = $this->cosine($v[0], $v[1]);
        $unrelated = $this->cosine($v[0], $v[2]);

        return [
            $similar > $unrelated,
            sprintf('ابعاد %d | هم‌معنی %.3f | نامرتبط %.3f', count($v[0]), $similar, $unrelated),
        ];
    }

    private function testTools(string $provider): array
    {
        $text = (string) agent(instructions: self::INSTRUCTIONS, tools: [new GetOrderStatus])
            ->prompt('وضعیت سفارش شماره‌ی ۴۵۷۲ چیست؟', provider: $provider, timeout: $this->timeout());

        return [str_contains($text, 'TRK-9931'), mb_substr($text, 0, 80)];
    }

    private function measure(callable $test): array
    {
        $start = microtime(true);

        try {
            [$ok, $note] = $test();
        } catch (Throwable $e) {
            return ['ok' => false, 'label' => '❌ خطا', 'note' => mb_substr($e->getMessage(), 0, 150)];
        }

        $ms = (int) ((microtime(true) - $start) * 1000);

        return ['ok' => $ok, 'ms' => $ms, 'label' => ($ok ? '✅ ' : '⚠️ ') . "{$ms}ms", 'note' => $note];
    }

    private function isPersian(string $text): bool
    {
        $persian = preg_match_all('/\p{Arabic}/u', $text);
        $latin = preg_match_all('/[A-Za-z]/', $text);
        $cjk = preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text);

        return $cjk === 0 && $persian > 0 && $persian / max(1, $persian + $latin) > 0.7;
    }

    private function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;

        foreach ($a as $i => $x) {
            $dot += $x * $b[$i];
            $na += $x * $x;
            $nb += $b[$i] * $b[$i];
        }

        $norm = sqrt($na) * sqrt($nb);

        return $norm > 0 ? $dot / $norm : 0.0;
    }

    private function testGrounded(string $provider): array
{
    $text = (string) agent(instructions: self::INSTRUCTIONS . ' فقط بر اساس متن داده‌شده پاسخ بده.')
        ->prompt(
            "متن:\n" . self::CONTEXT . "\n\nسؤال: درخواست مرخصی چند روز قبل از شروع آن باید ثبت شود؟",
            provider: $provider,
            timeout: $this->timeout(),
        );

    return [(bool) preg_match('/(3|۳|سه)\s*روز/u', $text), mb_substr($text, 0, 80)];
}

private function testRefusal(string $provider): array
{
    $text = (string) agent(instructions: self::INSTRUCTIONS
            . ' فقط بر اساس متن داده‌شده پاسخ بده. اگر پاسخ در متن نیست، دقیقاً بنویس: «در متن نیامده است».')
        ->prompt(
            "متن:\n" . self::CONTEXT . "\n\nسؤال: حقوق کارمندان در چه روزی از ماه پرداخت می‌شود؟",
            provider: $provider,
            timeout: $this->timeout(),
        );

    return [str_contains($text, 'در متن نیامده'), mb_substr($text, 0, 80)];
}
}