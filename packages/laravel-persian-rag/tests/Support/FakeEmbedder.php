<?php

namespace Js199069\PersianRag\Tests\Support;

use Js199069\PersianRag\Contracts\Embedder;
use Js199069\PersianRag\Support\PersianNormalizer;

/**
 * embedding قطعی بدون مدل: هر کلمه‌ی سه‌حرفی به بالا یک بُعد را روشن می‌کند.
 * متن‌هایی که کلمه‌ی مشترک دارند شبیه می‌شوند؛ برای تست فیلتر دسترسی همین کافی است.
 */
final class FakeEmbedder implements Embedder
{
    public function __construct(private int $dimensions = 1024) {}

    public function embed(array $texts, string $provider): array
    {
        return array_map(fn (string $text) => $this->vector($text), array_values($texts));
    }

    /** @return float[] */
    public function vector(string $text): array
    {
        $v = array_fill(0, $this->dimensions, 0.0);
        preg_match_all('/[\p{L}\p{N}]+/u', PersianNormalizer::searchForm($text), $m);
        foreach ($m[0] as $word) {
            if (mb_strlen($word) >= 3) {
                $v[crc32($word) % $this->dimensions] += 1.0;
            }
        }

        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));
        if ($norm == 0.0) {
            $v[0] = 1.0;     // بردار صفر در فاصله‌ی cosine تعریف‌نشده است

            return $v;
        }

        return array_map(fn ($x) => $x / $norm, $v);
    }
}
