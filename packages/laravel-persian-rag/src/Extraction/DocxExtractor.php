<?php

namespace Js199069\PersianRag\Extraction;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

final class DocxExtractor implements DocumentExtractor
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function supports(string $path): bool
    {
        return str_ends_with(strtolower($path), '.docx');
    }

    public function extract(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('اکستنشن zip در PHP فعال نیست');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('فایل DOCX باز نشد');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('word/document.xml در فایل نیست');
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);

        $body = $xp->query('//w:body')->item(0);
        if (! $body) {
            return [''];
        }

        $lines = [];
        foreach ($body->childNodes as $node) {
            if ($node->localName === 'p') {
                $lines[] = $this->paragraphText($xp, $node);
            } elseif ($node->localName === 'tbl') {
                // هر ردیف جدول یک خط؛ سلول‌ها با | جدا می‌شوند
                foreach ($xp->query('.//w:tr', $node) as $tr) {
                    $cells = [];
                    foreach ($xp->query('./w:tc', $tr) as $tc) {
                        $parts = [];
                        foreach ($xp->query('.//w:p', $tc) as $p) {
                            $parts[] = $this->paragraphText($xp, $p);
                        }
                        $cells[] = trim(implode(' ', $parts));
                    }
                    $lines[] = implode(' | ', $cells);
                }
            }
        }

        // DOCX مفهوم صفحه ندارد؛ کل سند یک «صفحه» است
        return [implode("\n", $lines)];
    }

    private function paragraphText(DOMXPath $xp, DOMNode $p): string
    {
        $text = '';
        foreach ($xp->query('.//w:t | .//w:tab | .//w:br', $p) as $n) {
            $text .= match ($n->localName) {
                't' => $n->textContent,
                'tab' => ' ',
                'br' => "\n",
                default => '',
            };
        }

        return $text;
    }
}