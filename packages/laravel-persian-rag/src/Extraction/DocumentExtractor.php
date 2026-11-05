<?php

namespace Js199069\PersianRag\Extraction;

interface DocumentExtractor
{
    public function supports(string $path): bool;

    /**
     * متن خام سند، صفحه به صفحه.
     *
     * @return string[]
     */
    public function extract(string $path): array;
}