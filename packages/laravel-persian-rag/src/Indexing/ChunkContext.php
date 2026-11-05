<?php

namespace Js199069\PersianRag\Indexing;

/**
 * زمینه‌ای که قبل از هر تکه می‌آید، هم در embedding و هم در search_text.
 * تغییر این متن یعنی ایندکس ساخته‌شده دیگر با سؤال‌ها هم‌خوان نیست: rag:index --fresh
 */
final class ChunkContext
{
    /** @param  array<string, string>  $audienceLabels */
    public static function text(string $title, string $audience, array $audienceLabels): string
    {
        return "عنوان سند: {$title}\nمخاطب: " . ($audienceLabels[$audience] ?? $audience);
    }
}
