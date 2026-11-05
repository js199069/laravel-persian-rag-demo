<?php

namespace Js199069\PersianRag\Search;

use Js199069\PersianRag\Contracts\Embedder;
use Laravel\Ai\Embeddings;

final class LaravelAiEmbedder implements Embedder
{
    public function embed(array $texts, string $provider): array
    {
        return Embeddings::for($texts)->generate($provider)->embeddings;
    }
}
