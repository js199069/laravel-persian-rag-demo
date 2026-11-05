<?php

namespace Js199069\PersianRag\Support;

use Normalizer;

final class PersianNormalizer
{
    private const MAP = [
        'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * املاهای رایجِ یک کلمه، فقط برای جست‌وجو و embedding (متن نمایشی دست نمی‌خورد).
     * این فهرست بر اساس خطاهای واقعی ارزیابی بزرگ می‌شود.
     */
    private const VARIANTS = [
        'هیئت' => 'هیات', 'هیأت' => 'هیات',
        'آئین' => 'آیین',
        'رئیس' => 'رییس',
        'رؤسای' => 'روسای', 'رؤسا' => 'روسا',
        'مسئول' => 'مسوول',
    ];

    /** متن تمیز برای نمایش؛ خط‌های جدید حفظ می‌شوند */
    public static function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // اصلاح «شکل‌های نمایشی» عربی که در PDFهای قدیمی رایج است (نیاز به اکستنشن intl)
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        }

        $text = strtr($text, self::MAP);

        // لیگاچر «لا» که pdftotext گاهی برعکس می‌خواند: «ساالنه» ← «سالانه»، «کاال» ← «کالا»
        $text = str_replace('اال', 'الا', $text);

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);                // اعراب و کشیده
        $text = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{00AD}\x{FEFF}]/u', '', $text); // نویسه‌های کنترلی نامرئی
        $text = preg_replace('/[ \t]*\x{200C}[ \t]*/u', "\u{200C}", $text);                      // نیم‌فاصله‌ی تمیز
        $text = preg_replace('/\x{200C}{2,}/u', "\u{200C}", $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /** شکل یکسان برای embedding و جست‌وجو: نرمال‌سازی + یکسان‌سازی املاهای رایج */
    public static function canonical(string $text): string
    {
        return strtr(self::normalize($text), self::VARIANTS);
    }

    /** شکل مخصوص جست‌وجوی متنی: canonical + نیم‌فاصله ← فاصله، همه‌چیز در یک خط */
    public static function searchForm(string $text): string
    {
        return preg_replace('/\s+/u', ' ', str_replace("\u{200C}", ' ', self::canonical($text)));
    }
}