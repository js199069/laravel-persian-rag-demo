<?php

namespace Js199069\PersianRag\Support;

final class TextQuality
{
    // حروف فارسی و عربی (بدون کشیده، ارقام و علائم)
    public const LETTERS = '\x{0621}-\x{063A}\x{0641}-\x{064A}\x{067E}\x{0686}\x{0698}\x{06A9}\x{06AF}\x{06CC}\x{06C0}';

    /** @var array<string, true> */
    private array $lexicon;

    /** @param string[] $words */
    public function __construct(array $words)
    {
        $this->lexicon = array_fill_keys($words, true);
    }

    /** @return string[] کلمه‌ها، بدون نیم‌فاصله (تا «شیوه‌نامه» و «شیوهنامه» یکی شوند) */
    public static function tokens(string $text): array
    {
        preg_match_all('/[' . self::LETTERS . ']+(?:\x{200C}[' . self::LETTERS . ']+)*/u', $text, $matches);

        $tokens = [];
        foreach ($matches[0] as $token) {
            $key = str_replace("\u{200C}", '', $token);
            if (mb_strlen($key) >= 3) {
                $tokens[] = $key;
            }
        }

        return $tokens;
    }

    /** درصد کلمه‌های معتبر (۰ تا ۱۰۰) */
    public function score(string $text): int
    {
        $tokens = self::tokens($text);
        if ($tokens === []) {
            return 0;
        }

        return (int) round(100 * $this->validCount($tokens) / count($tokens));
    }

/**
 * حذف محافظه‌کارانه‌ی خط‌های نویز OCR (مهر، امضا، سربرگ).
 * اصل: حذف متن واقعی بدتر از نگه‌داشتن نویز است؛ پس فقط خط‌هایی حذف می‌شوند
 * که از ساختارشان پیداست نویزند، نه از کلمه‌هایشان.
 *
 * @return array{0: string, 1: string[]} متن تمیز و خط‌های حذف‌شده
 */
public function cleanLines(string $text): array
{
    $kept = [];
    $removed = [];

    foreach (explode("\n", $text) as $line) {
        $trim = trim($line);

        if ($trim === '') {
            $kept[] = '';
            continue;
        }

        // بند شماره‌دار (مثل «8- تهیه...» یا «-9 تجاهر...»): متن واقعی
        if (preg_match('/^(?:\d{1,3}\s*[-–.)]|[-–]\s*\d{1,3})/u', $trim)) {
            $kept[] = $line;
            continue;
        }

        $tokens = self::tokens($trim);

        // دو کلمه‌ی سه‌حرفی یا بیشتر: همیشه نگه دار (مثل «انسان در اسلام» یا خانه‌ی جدول)
        if (count($tokens) >= 2) {
            $kept[] = $line;
            continue;
        }

        $short = mb_strlen($trim) < 20;

        // بدون کلمه‌ی سه‌حرفی، و کوتاه: نویز (مثل «33»، «#7»، «ام ای»)
        if ($tokens === [] && $short) {
            $removed[] = $trim;
            continue;
        }

        // فقط یک کلمه، ناشناخته، و کوتاه: نویز (مثل «هدز»، «0 ره سر پپوست:»)
        if (count($tokens) === 1 && ! isset($this->lexicon[$tokens[0]]) && $short) {
            $removed[] = $trim;
            continue;
        }

        $kept[] = $line;
    }

    $clean = preg_replace('/\n{3,}/u', "\n\n", implode("\n", $kept));

    return [trim($clean), $removed];
}

    private function validCount(array $tokens): int
    {
        return count(array_filter($tokens, fn ($w) => isset($this->lexicon[$w])));
    }
}