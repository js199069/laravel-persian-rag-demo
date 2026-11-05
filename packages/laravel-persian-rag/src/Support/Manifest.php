<?php

namespace Js199069\PersianRag\Support;

/** خواندن manifest.csv: UTF-8 یا Windows-1256، با یا بدون BOM، جداکننده‌ی «,» یا «;» */
final class Manifest
{
    /** @return list<array<string, string>> */
    public static function read(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($path));
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = iconv('Windows-1256', 'UTF-8//IGNORE', $content);
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $first = array_shift($lines);
        if ($first === null || trim($first) === '') {
            return [];
        }
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map('trim', str_getcsv($first, $delimiter, '"', ''));

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = array_slice(array_pad(str_getcsv($line, $delimiter, '"', ''), count($header), ''), 0, count($header));
            $rows[] = array_combine($header, $values);
        }

        return $rows;
    }

    /** @return array<string, array<string, string>> شناسه ← ردیف */
    public static function keyed(string $path): array
    {
        $rows = [];
        foreach (self::read($path) as $row) {
            if (isset($row['id']) && $row['id'] !== '') {
                $rows[$row['id']] = $row;
            }
        }

        return $rows;
    }
}
