<?php

namespace Js199069\PersianRag\Tests\Unit;

use Js199069\PersianRag\Answering\AnswerParser;
use PHPUnit\Framework\TestCase;

final class AnswerParserTest extends TestCase
{
    private const REFUSAL = 'پاسخ این سؤال در اسناد موجود نیامده است.';

    private function parse(string $text): array
    {
        return AnswerParser::parse($text, self::REFUSAL, [1, 2, 3]);
    }

    public function test_answer_with_citation(): void
    {
        $r = $this->parse('یک واحد درس نظری طی 16 جلسه یک ساعتی آموزش داده می‌شود [2].');

        $this->assertSame([2], $r['cited']);
        $this->assertSame([], $r['invalid_citations']);
        $this->assertFalse($r['refused']);
        $this->assertFalse($r['mixed']);
    }

    public function test_pure_refusal(): void
    {
        $r = $this->parse(self::REFUSAL);

        $this->assertTrue($r['refused']);
        $this->assertFalse($r['mixed']);
        $this->assertSame([], $r['cited']);
    }

    public function test_refusal_with_stray_citation_is_still_pure(): void
    {
        $this->assertTrue($this->parse(self::REFUSAL . ' [1]')['refused']);
    }

    public function test_answer_plus_refusal_is_mixed(): void
    {
        $r = $this->parse('یک واحد درس نظری طی 16 جلسه آموزش داده می‌شود [2]. ' . self::REFUSAL);

        $this->assertFalse($r['refused']);
        $this->assertTrue($r['mixed']);
    }

    public function test_citation_to_missing_source_is_reported(): void
    {
        $r = $this->parse('جواب این است [1] و [6].');

        $this->assertSame([1, 6], $r['cited']);
        $this->assertSame([6], $r['invalid_citations']);
    }

    public function test_persian_digits_and_duplicates_in_citations(): void
    {
        $this->assertSame([2], $this->parse('جواب [۲] و دوباره [2].')['cited']);
    }
}
