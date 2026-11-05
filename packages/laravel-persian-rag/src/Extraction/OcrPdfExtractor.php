<?php

namespace Js199069\PersianRag\Extraction;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class OcrPdfExtractor implements DocumentExtractor
{
    public function __construct(
        private int $dpi = 300,
        private string $lang = 'fas',
        private string $tesseract = 'tesseract',
        private string $pdftoppm = 'pdftoppm',
        private ?string $tmpPath = null,
    ) {}

    public function supports(string $path): bool
    {
        return str_ends_with(strtolower($path), '.pdf');
    }

    public function extract(string $path): array
    {
        $base = $this->tmpPath ?? storage_path('app/ocr-tmp');
        $tmp = rtrim($base, '/\\') . '/' . pathinfo($path, PATHINFO_FILENAME);
        File::ensureDirectoryExists($tmp);
        File::cleanDirectory($tmp);

        try {
            $render = Process::timeout(900)->run([$this->pdftoppm, '-r', (string) $this->dpi, '-png', $path, $tmp . '/page']);
            if ($render->failed()) {
                throw new RuntimeException('pdftoppm: ' . trim($render->errorOutput()));
            }

            $images = glob($tmp . '/page*.png') ?: [];
            natsort($images);

            if ($images === []) {
                throw new RuntimeException('pdftoppm produced no images');
            }

            $pages = [];
            foreach (array_values($images) as $image) {
                $result = Process::timeout(300)->run([$this->tesseract, $image, 'stdout', '-l', $this->lang]);
                if ($result->failed()) {
                    throw new RuntimeException('tesseract: ' . trim($result->errorOutput()));
                }
                $pages[] = $result->output();
            }

            return $pages;
        } finally {
            File::deleteDirectory($tmp);
        }
    }
}
