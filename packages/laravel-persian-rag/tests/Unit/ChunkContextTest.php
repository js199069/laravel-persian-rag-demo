<?php

namespace Js199069\PersianRag\Tests\Unit;

use Js199069\PersianRag\Indexing\ChunkContext;
use PHPUnit\Framework\TestCase;

/**
 * این متن بخشی از embedding همه‌ی تکه‌هاست. اگر این تست شکست خورد، یعنی متن زمینه عوض شده
 * و ایندکس موجود دیگر با سؤال‌ها هم‌خوان نیست: یا تغییر را برگردان، یا rag:index --fresh.
 */
final class ChunkContextTest extends TestCase
{
    public function test_context_text_is_locked(): void
    {
        $this->assertSame(
            "عنوان سند: آیین‌نامه تخلفات اعضای هیئت علمی\nمخاطب: اعضای هیئت علمی",
            ChunkContext::text('آیین‌نامه تخلفات اعضای هیئت علمی', 'faculty', ['faculty' => 'اعضای هیئت علمی']),
        );
    }

    public function test_unknown_audience_falls_back_to_its_key(): void
    {
        $this->assertSame("عنوان سند: الف\nمخاطب: staff", ChunkContext::text('الف', 'staff', []));
    }
}
