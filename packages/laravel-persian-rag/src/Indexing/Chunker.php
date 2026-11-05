<?php

namespace Js199069\PersianRag\Indexing;

final class Chunker
{
    public function __construct(
        private int $maxChars = 1000,
        private int $overlapChars = 250,
    ) {}

    /**
     * @param  array<int, array{page: int, text: string}>  $pages
     * @return array<int, array{content: string, page_from: int, page_to: int}>
     */
    public function chunk(array $pages): array
    {
        // ۱. شکستن متن هر صفحه به واحدهای کوچک (پاراگراف، جمله، یا تکه‌ی کلمه‌ای)
        $units = [];
        foreach ($pages as $page) {
            foreach ($this->units($page['text']) as $text) {
                $units[] = ['text' => $text, 'page' => $page['page']];
            }
        }

        // ۲. چیدن واحدها کنار هم تا سقف طول تکه، با هم‌پوشانی آخرین واحد کوتاه
        $chunks = [];
        $current = [];
        $length = 0;

        foreach ($units as $unit) {
            $unitLength = mb_strlen($unit['text']);

            if ($current !== [] && $length + $unitLength > $this->maxChars) {
                $chunks[] = $this->build($current);

                $last = end($current);
                $current = mb_strlen($last['text']) <= $this->overlapChars ? [$last] : [];
                $length = $current !== [] ? mb_strlen($last['text']) + 1 : 0;
            }

            $current[] = $unit;
            $length += $unitLength + 1;
        }

        if ($current !== []) {
            $chunks[] = $this->build($current);
        }

        return $chunks;
    }

    /** @return string[] */
    private function units(string $text): array
    {
        $units = [];

        foreach (preg_split('/\n\s*\n/u', $text) as $paragraph) {
            $paragraph = trim(preg_replace('/\s*\n\s*/u', ' ', $paragraph));
            if ($paragraph === '') {
                continue;
            }
            if (mb_strlen($paragraph) <= $this->maxChars) {
                $units[] = $paragraph;
                continue;
            }

            // پاراگراف بلند: شکستن به جمله
            foreach (preg_split('/(?<=[.؟!؛])\s+/u', $paragraph) as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }
                if (mb_strlen($sentence) <= $this->maxChars) {
                    $units[] = $sentence;
                    continue;
                }

                // جمله‌ی خیلی بلند: شکستن بر اساس کلمه
                $buffer = '';
                foreach (preg_split('/\s+/u', $sentence) as $word) {
                    if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($word) + 1 > $this->maxChars) {
                        $units[] = $buffer;
                        $buffer = '';
                    }
                    $buffer = $buffer === '' ? $word : "{$buffer} {$word}";
                }
                if ($buffer !== '') {
                    $units[] = $buffer;
                }
            }
        }

        return $units;
    }

    private function build(array $units): array
    {
        return [
            'content' => implode("\n", array_column($units, 'text')),
            'page_from' => $units[0]['page'],
            'page_to' => end($units)['page'],
        ];
    }
}