<?php

namespace Js199069\PersianRag\Extraction;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class PdfTextExtractor implements DocumentExtractor
{
    public function __construct(
        private bool $layout = false,
        private string $binary = 'pdftotext',
    ) {}

    public function supports(string $path): bool
    {
        return str_ends_with(strtolower($path), '.pdf');
    }

    public function extract(string $path): array
    {
        $command = [$this->binary, '-enc', 'UTF-8'];
        if ($this->layout) {
            $command[] = '-layout';
        }
        $command[] = $path;
        $command[] = '-';

        $result = Process::timeout(120)->run($command);

        if ($result->failed()) {
            throw new RuntimeException('pdftotext: ' . trim($result->errorOutput() ?: $result->output()));
        }

        // pdftotext آخر هر صفحه یک form feed می‌گذارد
        $pages = explode("\f", $result->output());
        if ($pages !== [] && trim(end($pages)) === '') {
            array_pop($pages);
        }

        return $pages;
    }
}
