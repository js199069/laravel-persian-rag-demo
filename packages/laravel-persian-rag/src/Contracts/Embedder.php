<?php

namespace Js199069\PersianRag\Contracts;

/** ساختن embedding؛ در برنامه laravel/ai، در تست یک نسخه‌ی ساختگی و قطعی */
interface Embedder
{
    /**
     * @param  string[]  $texts
     * @return array<int, float[]>
     */
    public function embed(array $texts, string $provider): array;
}
