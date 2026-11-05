<?php

namespace Js199069\PersianRag\Answering;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

// temperature پایین: همان سؤال، همان جواب
#[Temperature(0.1)]
#[MaxTokens(400)]
final class RagAnswerAgent implements Agent
{
    use Promptable;

    public function __construct(private string $instructions) {}

    public function instructions(): string
    {
        return $this->instructions;
    }
}