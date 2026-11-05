<?php

namespace Js199069\PersianRag\Tests\Feature;

use InvalidArgumentException;
use Js199069\PersianRag\Rag;
use Js199069\PersianRag\Tests\DatabaseTestCase;

/**
 * کنترل دسترسی روی PostgreSQL واقعی با pgvector.
 * فیلتر داخل خود کوئری است، پس باید با دیتابیس واقعی تست شود، نه با mock.
 */
final class AccessControlTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->document('doc-faculty', 'faculty', 'آیین‌نامه تخلفات اعضای هیئت علمی', [
            'تهیه یا مصرف مواد مخدر توسط عضو هیئت علمی از تخلفات انتظامی است.',
        ]);
        $this->document('doc-students', 'all_students', 'شیوه‌نامه انضباطی دانشجویان', [
            'مصرف مواد مخدر توسط دانشجو تخلف انضباطی است و در کمیته انضباطی رسیدگی می‌شود.',
        ]);
        $this->document('doc-phd', 'phd', 'آیین‌نامه دوره دکتری', [
            'آزمون جامع دکتری در هر سال تحصیلی دو بار برگزار می‌شود.',
        ]);
        // برچسب تایپی: در هیچ نقشی نیست
        $this->document('doc-orphan', 'students', 'سند با برچسب اشتباه', [
            'آزمون جامع و مواد مخدر و هیئت علمی و دانشجو در این سند آمده‌اند.',
        ]);
        $this->document('doc-long', 'all_students', 'آیین‌نامه آموزشی', [
            'تقویم آموزشی و انتخاب واحد در ابتدای هر نیمسال تحصیلی انجام می‌شود.',
            'تقویم آموزشی هر نیمسال توسط شورای آموزشی تصویب می‌شود.',
            'انتخاب واحد خارج از تقویم آموزشی فقط با مجوز شورای آموزشی ممکن است.',
            'حذف و اضافه‌ی واحد طبق تقویم آموزشی در هفته‌ی دوم نیمسال انجام می‌شود.',
        ]);
    }

    /** @return string[] */
    private function docKeys(string $question, ?string $role, int $k = 10): array
    {
        $found = app(Rag::class)->search($question, $role, $k);

        return array_map(fn ($hit) => $hit['chunk']->document->doc_key, $found['results']);
    }

    public function test_faculty_sees_faculty_regulation(): void
    {
        $keys = $this->docKeys('مصرف مواد مخدر توسط عضو هیئت علمی چه تخلفی است؟', 'faculty');

        $this->assertContains('doc-faculty', $keys);
    }

    public function test_student_never_sees_faculty_regulation_even_when_asking_about_it(): void
    {
        $keys = $this->docKeys('مصرف مواد مخدر توسط عضو هیئت علمی چه تخلفی است؟', 'bachelor');

        $this->assertNotContains('doc-faculty', $keys);
        $this->assertContains('doc-students', $keys, 'فیلتر نباید همه‌چیز را حذف کند');
    }

    public function test_bachelor_does_not_see_phd_documents(): void
    {
        $question = 'آزمون جامع دکتری چند بار برگزار می‌شود؟';

        $this->assertNotContains('doc-phd', $this->docKeys($question, 'bachelor'));
        $this->assertContains('doc-phd', $this->docKeys($question, 'phd'));
    }

    public function test_document_with_unknown_audience_is_invisible_to_every_role(): void
    {
        $question = 'آزمون جامع و مواد مخدر و هیئت علمی';

        foreach (app(Rag::class)->roles() as $role) {
            $this->assertNotContains('doc-orphan', $this->docKeys($question, $role), "نقش {$role}");
        }
        $this->assertContains('doc-orphan', $this->docKeys($question, null), 'بدون فیلتر (مدیر) دیده می‌شود');
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Rag::class)->search('آزمون جامع', 'student');
    }

    public function test_at_most_two_chunks_per_document(): void
    {
        $keys = $this->docKeys('تقویم آموزشی انتخاب واحد نیمسال', null);

        $this->assertLessThanOrEqual(2, count(array_keys($keys, 'doc-long')));
    }

    public function test_result_count_respects_k(): void
    {
        $this->assertLessThanOrEqual(2, count($this->docKeys('تقویم آموزشی', null, 2)));
    }
}
