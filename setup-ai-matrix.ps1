# setup-ai-matrix.ps1 — ساخت فایل‌های هفته‌ی اول پروژه‌ی RAG فارسی
# در ریشه‌ی پروژه‌ی لاراول اجرا شود.

$ErrorActionPreference = 'Stop'
$utf8NoBom = New-Object System.Text.UTF8Encoding $false

function Write-ProjectFile([string]$Path, [string]$Content) {
    $full = Join-Path (Get-Location) $Path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [System.IO.File]::WriteAllText($full, $Content, $utf8NoBom)
    Write-Host "ساخته شد: $Path" -ForegroundColor Green
}

if (-not (Test-Path 'artisan')) {
    Write-Host 'این اسکریپت باید در ریشه‌ی پروژه‌ی لاراول اجرا شود.' -ForegroundColor Red
    exit 1
}
if (-not (Test-Path 'config/ai.php')) {
    Write-Host 'config/ai.php پیدا نشد. اول laravel/ai را نصب و vendor:publish را اجرا کن.' -ForegroundColor Red
    exit 1
}

# ---------- app/Ai/Tools/GetOrderStatus.php ----------
Write-ProjectFile 'app/Ai/Tools/GetOrderStatus.php' @'
<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetOrderStatus implements Tool
{
    public function description(): Stringable|string
    {
        return 'وضعیت یک سفارش را با شماره‌ی آن برمی‌گرداند.';
    }

    public function handle(Request $request): Stringable|string
    {
        // کد رهگیری را فقط این ابزار می‌داند؛ اگر در جواب مدل آمد، یعنی ابزار واقعاً صدا زده شده
        return "سفارش {$request['order_id']}: تحویل پست شده، کد رهگیری TRK-9931";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->string()->required(),
        ];
    }
}
'@

# ---------- app/Console/Commands/AiMatrix.php ----------
Write-ProjectFile 'app/Console/Commands/AiMatrix.php' @'
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
            ];
        }

        $rows = [];
        foreach ($results as $provider => $tests) {
            $rows[] = [$provider, ...array_values(array_map(fn ($t) => $t['label'], $tests))];
        }
        $this->table(['Provider', 'Chat', 'Streaming', 'Embedding', 'Tool calling'], $rows);

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
}
'@

# ---------- Dockerfile (اختیاری، برای ریپوی نهایی) ----------
Write-ProjectFile 'Dockerfile' @'
FROM php:8.4-cli AS build
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql zip pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize

FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq5 libzip4 poppler-utils \
    && rm -rf /var/lib/apt/lists/*
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=build /app /app
WORKDIR /app
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
'@

# ---------- docker-compose.yml (اختیاری، برای ریپوی نهایی) ----------
Write-ProjectFile 'docker-compose.yml' @'
x-logging: &logging
  logging:
    options:
      max-size: "10m"
      max-file: "2"

services:
  app:
    build: .
    ports:
      - "8000:8000"
    env_file: .env
    environment:
      OLLAMA_URL: http://ollama:11434/v1
      DB_HOST: postgres
    depends_on:
      - postgres
      - ollama
    <<: *logging

  postgres:
    image: pgvector/pgvector:pg17
    pull_policy: missing
    command: postgres -c shared_buffers=128MB
    environment:
      POSTGRES_DB: rag
      POSTGRES_USER: rag
      POSTGRES_PASSWORD: secret
    volumes:
      - pgdata:/var/lib/postgresql/data
    ports:
      - "5432:5432"
    <<: *logging

  ollama:
    image: ollama/ollama
    pull_policy: missing
    volumes:
      - ollama:/root/.ollama
    ports:
      - "11434:11434"
    <<: *logging

volumes:
  pgdata:
  ollama:
'@

# ---------- config/ai.php: اضافه کردن دو provider ----------
$configPath = Join-Path (Get-Location) 'config/ai.php'
$config = [System.IO.File]::ReadAllText($configPath, $utf8NoBom)

if ($config -match "'ollama_local'") {
    Write-Host 'config/ai.php: providerها از قبل وجود دارند؛ تغییری داده نشد.' -ForegroundColor Yellow
} else {
    $providers = @'

        'ollama_local' => [
            'driver' => 'openai-compatible',
            'url' => env('OLLAMA_URL'),
            'models' => [
                'text' => ['default' => env('OLLAMA_CHAT_MODEL')],
                'embeddings' => ['default' => env('OLLAMA_EMBED_MODEL')],
            ],
        ],

        'iran_api' => [
            'driver' => 'openai-compatible',
            'url' => env('IRAN_API_URL'),
            'key' => env('IRAN_API_KEY'),
            'models' => [
                'text' => ['default' => env('IRAN_API_CHAT_MODEL')],
                'embeddings' => ['default' => env('IRAN_API_EMBED_MODEL')],
            ],
        ],
'@
    $regex = [regex]"('providers'\s*=>\s*\[)"
    if (-not $regex.IsMatch($config)) {
        Write-Host "config/ai.php: آرایه‌ی 'providers' پیدا نشد؛ دو provider را دستی اضافه کن." -ForegroundColor Red
    } else {
        $config = $regex.Replace($config, ('$1' + $providers), 1)
        [System.IO.File]::WriteAllText($configPath, $config, $utf8NoBom)
        Write-Host 'به‌روز شد: config/ai.php' -ForegroundColor Green
    }
}

# ---------- .env: اضافه کردن تنظیمات هوش مصنوعی ----------
$envPath = Join-Path (Get-Location) '.env'
$envText = [System.IO.File]::ReadAllText($envPath, $utf8NoBom)

if ($envText -match 'OLLAMA_URL=') {
    Write-Host '.env: تنظیمات از قبل وجود دارند؛ تغییری داده نشد.' -ForegroundColor Yellow
} else {
    $envBlock = @'

# --- مدل محلی (Ollama بومی ویندوز، از طریق API سازگار با OpenAI) ---
OLLAMA_URL=http://localhost:11434/v1
OLLAMA_CHAT_MODEL=qwen2.5:3b
OLLAMA_EMBED_MODEL=bge-m3

# --- سرویس واسط داخلی (آدرس و نام مدل‌ها از مستندات همان سرویس) ---
IRAN_API_URL=
IRAN_API_KEY=
IRAN_API_CHAT_MODEL=
IRAN_API_EMBED_MODEL=
'@
    [System.IO.File]::WriteAllText($envPath, $envText + $envBlock, $utf8NoBom)
    Write-Host 'به‌روز شد: .env' -ForegroundColor Green
}

Write-Host ''
Write-Host 'تمام شد. قدم بعد:' -ForegroundColor Cyan
Write-Host '  ollama pull qwen2.5:3b'
Write-Host '  ollama pull bge-m3'
Write-Host '  php artisan ai:matrix ollama_local'