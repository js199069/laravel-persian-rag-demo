<?php

namespace Js199069\PersianRag\Tests\Feature;

use Js199069\PersianRag\Rag;
use Js199069\PersianRag\Tests\DatabaseTestCase;

/**
 * باگ پیداشده در هفته‌ی ۵: «یک واحد نظری چند جلسه است؟» بیشترین شباهت ۰٫۷۱۴ داشت،
 * ولی آن تکه در نتایج نبود. تکه‌های دیگرِ همان سند که در هر دو جست‌وجو آمده بودند
 * در RRF جلو زدند و سقف «۲ تکه از هر سند» بهترین تکه‌ی معنایی را حذف کرد.
 */
final class SearchRankingTest extends DatabaseTestCase
{
    public function test_best_vector_match_is_kept_even_when_document_cap_is_full(): void
    {
        $question = 'تقویم آموزشی انتخاب واحد نیمسال';

        // عنوان عمداً هیچ کلمه‌ای از سؤال ندارد (عنوان هم در متن جست‌وجو هست)
        $regulation = $this->createDocument('doc-regulation', 'all_students', 'مقررات دانشگاه');
        $this->addChunk($regulation, 0, 'تقویم آموزشی و انتخاب واحد در ابتدای هر نیمسال انجام می‌شود.');
        $this->addChunk($regulation, 1, 'تقویم آموزشی هر نیمسال و زمان انتخاب واحد توسط شورا تعیین می‌شود.');

        // بهترین تکه‌ی برداری: embedding دقیقاً همان سؤال (شباهت ۱)، ولی متنش هیچ کلمه‌ای از سؤال ندارد،
        // پس در جست‌وجوی متنی نمی‌آید و در RRF عقب می‌افتد
        $best = $this->addChunk($regulation, 2, 'جزئیات این موضوع در بخشنامه‌ی جداگانه‌ای آمده است.', vectorOf: $question);

        $this->document('doc-other', 'all_students', 'راهنمای دانشجو', [
            'برنامه‌ی هر نیمسال تحصیلی روی سامانه اعلام می‌شود.',
        ]);

        $found = app(Rag::class)->search($question, null, 3);
        $ids = array_map(fn ($hit) => $hit['chunk']->id, $found['results']);
        $keys = array_map(fn ($hit) => $hit['chunk']->document->doc_key, $found['results']);

        $this->assertEqualsWithDelta(1.0, $found['best_similarity'], 0.001, 'بهترین شباهت مال همین تکه است');
        $this->assertContains($best->id, $ids, 'تکه‌ای که لایه‌ی ۱ با آن تصمیم گرفته، باید به مدل برسد');
        $this->assertLessThanOrEqual(2, count(array_keys($keys, 'doc-regulation')), 'سقف سند همچنان رعایت شود');
        $this->assertCount(3, $ids);
    }
}
