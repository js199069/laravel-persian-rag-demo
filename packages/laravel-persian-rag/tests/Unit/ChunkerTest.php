<?php

namespace Js199069\PersianRag\Tests\Unit;

use Js199069\PersianRag\Indexing\Chunker;
use PHPUnit\Framework\TestCase;

final class ChunkerTest extends TestCase
{
    private function sentence(int $n): string
    {
        return "جمله‌ی شماره‌ی {$n} درباره‌ی مقررات آموزشی است.";
    }

    public function test_empty_pages_give_no_chunks(): void
    {
        $this->assertSame([], (new Chunker())->chunk([['page' => 1, 'text' => "  \n\n "]]));
    }

    public function test_short_pages_are_merged_and_keep_page_range(): void
    {
        $chunks = (new Chunker())->chunk([
            ['page' => 1, 'text' => $this->sentence(1)],
            ['page' => 2, 'text' => $this->sentence(2)],
        ]);

        $this->assertCount(1, $chunks);
        $this->assertSame(1, $chunks[0]['page_from']);
        $this->assertSame(2, $chunks[0]['page_to']);
    }

    public function test_chunks_never_exceed_max_length(): void
    {
        $paragraph = implode(' ', array_map(fn ($n) => $this->sentence($n), range(1, 20)));
        $chunks = (new Chunker(100, 60))->chunk([['page' => 1, 'text' => $paragraph]]);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(100, mb_strlen($chunk['content']));
        }
    }

    public function test_next_chunk_starts_with_last_short_sentence_of_previous(): void
    {
        $paragraph = implode(' ', array_map(fn ($n) => $this->sentence($n), range(1, 6)));
        $chunks = (new Chunker(100, 60))->chunk([['page' => 1, 'text' => $paragraph]]);

        $lastOfFirst = array_slice(explode("\n", $chunks[0]['content']), -1)[0];
        $firstOfSecond = explode("\n", $chunks[1]['content'])[0];
        $this->assertSame($lastOfFirst, $firstOfSecond);
    }

    public function test_overlapping_chunk_spans_page_boundary(): void
    {
        $chunks = (new Chunker(100, 60))->chunk([
            ['page' => 1, 'text' => $this->sentence(1)],
            ['page' => 2, 'text' => $this->sentence(2)],
            ['page' => 3, 'text' => $this->sentence(3)],
        ]);

        $this->assertSame([1, 2], [$chunks[0]['page_from'], $chunks[0]['page_to']]);
        $this->assertSame([2, 3], [$chunks[1]['page_from'], $chunks[1]['page_to']]);
    }

    public function test_sentence_without_punctuation_is_split_by_words(): void
    {
        $text = implode(' ', array_fill(0, 300, 'کلمه'));
        $chunks = (new Chunker(100, 60))->chunk([['page' => 1, 'text' => $text]]);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(100, mb_strlen($chunk['content']));
        }
    }
}
