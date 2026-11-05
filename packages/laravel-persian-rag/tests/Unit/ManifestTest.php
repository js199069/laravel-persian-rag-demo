<?php

namespace Js199069\PersianRag\Tests\Unit;

use Js199069\PersianRag\Support\Manifest;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, $bytes);

        return $this->files[] = $path;
    }

    public function test_missing_file_gives_empty_list(): void
    {
        $this->assertSame([], Manifest::read(sys_get_temp_dir() . '/does-not-exist.csv'));
    }

    public function test_utf8_with_bom_and_quoted_comma(): void
    {
        $path = $this->file("\xEF\xBB\xBFid,file,audience,title\ndoc-01,doc-01.pdf,faculty,\"آیین‌نامه، نسخه‌ی جدید\"\n");
        $rows = Manifest::read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('doc-01', $rows[0]['id']);
        $this->assertSame('آیین‌نامه، نسخه‌ی جدید', $rows[0]['title']);
    }

    public function test_semicolon_delimiter_from_excel(): void
    {
        $rows = Manifest::read($this->file("id;file;audience\ndoc-02;doc-02.pdf;phd\n"));

        $this->assertSame('phd', $rows[0]['audience']);
    }

    public function test_windows_1256_is_converted(): void
    {
        if (! function_exists('iconv')) {
            $this->markTestSkipped('iconv لازم است');
        }
        $bytes = iconv('UTF-8', 'Windows-1256', "id,file,audience,title\ndoc-03,doc-03.pdf,phd,آزمون\n");
        $rows = Manifest::read($this->file($bytes));

        $this->assertSame('آزمون', $rows[0]['title']);
    }

    public function test_short_rows_are_padded_and_blank_lines_skipped(): void
    {
        $rows = Manifest::read($this->file("id,file,audience,title\r\ndoc-04,doc-04.pdf\r\n\r\n"));

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]['audience']);
        $this->assertSame('', $rows[0]['title']);
    }

    public function test_keyed_uses_id(): void
    {
        $rows = Manifest::keyed($this->file("id,file\ndoc-05,a.pdf\ndoc-06,b.pdf\n"));

        $this->assertSame(['doc-05', 'doc-06'], array_keys($rows));
        $this->assertSame('b.pdf', $rows['doc-06']['file']);
    }
}
