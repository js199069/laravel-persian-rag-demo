<?php

namespace Js199069\PersianRag\Answering;

use Js199069\PersianRag\Support\PersianNormalizer;

/** تشخیص ارجاع‌ها و سه حالت جواب (جواب، امتناع خالص، مختلط) از متن خروجی مدل */
final class AnswerParser
{
    /**
     * @param  int[]  $sourceNumbers  شماره‌ی منبع‌هایی که واقعاً به مدل داده شده
     * @return array{cited: int[], invalid_citations: int[], refused: bool, mixed: bool}
     */
    public static function parse(string $text, string $refusal, array $sourceNumbers): array
    {
        $parsed = PersianNormalizer::normalize($text);
        preg_match_all('/\[(\d+)\]/', $parsed, $matches);
        $cited = array_values(array_unique(array_map('intval', $matches[1])));

        $mentionsRefusal = str_contains($parsed, 'نیامده');
        $rest = preg_replace('/\[\d+\]/u', '', str_replace(PersianNormalizer::normalize($refusal), '', $parsed));
        $rest = preg_replace('/[\s.،,:؛]+/u', '', $rest);
        $pureRefusal = $mentionsRefusal && mb_strlen($rest) < 15;

        return [
            'cited' => $cited,
            'invalid_citations' => array_values(array_diff($cited, $sourceNumbers)),
            'refused' => $pureRefusal,
            'mixed' => $mentionsRefusal && ! $pureRefusal,
        ];
    }
}
