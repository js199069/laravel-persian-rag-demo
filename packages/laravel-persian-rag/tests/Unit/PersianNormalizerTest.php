<?php

namespace Js199069\PersianRag\Tests\Unit;

use Js199069\PersianRag\Support\PersianNormalizer as N;
use Normalizer;
use PHPUnit\Framework\TestCase;

final class PersianNormalizerTest extends TestCase
{
    public function test_arabic_yeh_and_kaf_become_persian(): void
    {
        $this->assertSame('علی کتاب', N::normalize('علي كتاب'));
    }

    public function test_persian_and_arabic_digits_become_latin(): void
    {
        $this->assertSame('سال 1405 و 3 واحد', N::normalize('سال ۱۴۰۵ و ٣ واحد'));
    }

    public function test_presentation_forms_are_decomposed(): void
    {
        if (! class_exists(Normalizer::class)) {
            $this->markTestSkipped('intl لازم است');
        }
        // «سلام» با شکل‌های نمایشی، از جمله لیگاچر «لا»
        $this->assertSame('سلام', N::normalize("\u{FEB3}\u{FEFC}\u{FEE1}"));
    }

    public function test_reversed_lam_alef_from_pdftotext_is_fixed(): void
    {
        $this->assertSame('سالانه', N::normalize('ساالنه'));
    }

    public function test_diacritics_and_kashida_are_removed(): void
    {
        $this->assertSame('مدرس', N::normalize('مُدَرِّس'));
        $this->assertSame('دانشگاه', N::normalize('دانشـــگاه'));
    }

    public function test_spaces_around_zwnj_are_removed(): void
    {
        $this->assertSame("کتاب\u{200C}ها", N::normalize("کتاب \u{200C} ها"));
    }

    public function test_paragraph_breaks_are_kept_but_collapsed(): void
    {
        $this->assertSame("الف\n\nب", N::normalize("الف\r\n\r\n\r\n\r\nب"));
    }

    public function test_display_text_keeps_original_spelling(): void
    {
        $this->assertSame('هیئت', N::normalize('هیئت'));
    }

    public function test_canonical_unifies_spelling_variants(): void
    {
        $this->assertSame('هیات علمی', N::canonical('هیئت علمی'));
        $this->assertSame('هیات علمی', N::canonical('هیأت علمی'));
        $this->assertSame("آیین\u{200C}نامه", N::canonical("آئین\u{200C}نامه"));
        $this->assertSame('رییس دانشگاه', N::canonical('رئیس دانشگاه'));
        $this->assertSame('روسای دانشکده', N::canonical('رؤسای دانشکده'));
    }

    public function test_search_form_is_one_line_without_zwnj(): void
    {
        $this->assertSame('آیین نامه جدید', N::searchForm("آئین\u{200C}نامه\nجدید"));
    }

    /**
     * تصمیم آگاهانه (هفته‌ی ۵): جدا کردن رقم از کلمه («16جلسه» ← «16 جلسه») امتحان و سنجیده شد.
     * کلمه‌های تازه‌آزادشده (مثل «پایه» در «1پایه») سندهای هم‌موضوع ولی نادرست را در جست‌وجوی متنی بالا آوردند:
     * رتبه‌ی ۱ از ۹۵٪ به ۹۰٪ و MRR از ۰٫۹۸ به ۰٫۹۴. برگردانده شد.
     * اگر رتبه‌بندی متنی بهتر شد (مثلاً وزن IDF یا reranker)، دوباره بسنج.
     */
    public function test_search_form_keeps_digits_attached_by_decision(): void
    {
        $this->assertSame('یک واحد طی 16جلسه', N::searchForm('یک واحد طی 16جلسه'));
    }

    /** embedding از canonical ساخته می‌شود؛ جدا کردن رقم فقط برای جست‌وجوی متنی است تا ایندکس برداری عوض نشود */
    public function test_canonical_keeps_digits_attached(): void
    {
        $this->assertSame('16جلسه', N::canonical('16جلسه'));
    }
}
