<?php

namespace Js199069\PersianRag;

use InvalidArgumentException;
use Js199069\PersianRag\Answering\AnswerGenerator;
use Js199069\PersianRag\Answering\RagAnswerAgent;
use Js199069\PersianRag\Search\HybridSearch;
use LogicException;

/**
 * نقطه‌ی ورود اصلی پکیج.
 *
 * نقش (role) همیشه باید از کاربر واردشده بیاید، نه از ورودی کاربر.
 * null یعنی بدون فیلتر دسترسی؛ فقط برای ابزارهای مدیریتی.
 */
final class Rag
{
    /** @param  array<string, string[]>  $roles */
    public function __construct(
        private HybridSearch $search,
        private AnswerGenerator $generator,
        private array $roles,
        private string $llmProvider,
    ) {}

    /** @return string[]|null */
    public function audiencesFor(?string $role): ?array
    {
        if ($role === null) {
            return null;
        }

        if (! isset($this->roles[$role])) {
            throw new InvalidArgumentException("Unknown role [{$role}]. Define it under 'roles' in config/persian-rag.php.");
        }

        return $this->roles[$role];
    }

    /** @return string[] */
    public function roles(): array
    {
        return array_keys($this->roles);
    }

    public function search(string $question, ?string $role = null, int $k = 5): array
    {
        return $this->search->search($question, $this->audiencesFor($role), $k);
    }

    /** جست‌وجو + منبع‌ها + پرامپت، بدون صدا زدن مدل زبانی */
    public function prepare(string $question, ?string $role = null): array
    {
        return $this->generator->prepare($question, $this->audiencesFor($role));
    }

    /** جواب کامل با ارجاع، تشخیص امتناع و بررسی ارجاع‌ها */
    public function answer(string $question, ?string $role = null): array
    {
        return $this->generator->answer($question, $this->audiencesFor($role));
    }

    /** استریم جواب برای خروجی prepare() */
    public function stream(array $prepared)
    {
        if ($prepared['refused'] ?? false) {
            throw new LogicException('Nothing to stream: the question was refused at retrieval.');
        }

        return (new RagAnswerAgent($prepared['instructions']))
            ->stream($prepared['prompt'], provider: $this->llmProvider);
    }
}
