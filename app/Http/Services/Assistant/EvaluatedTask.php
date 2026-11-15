<?php

namespace App\Http\Services\Assistant;

use Carbon\CarbonImmutable;

/** A catalog step after the engine has judged it for one event. */
final class EvaluatedTask
{
    /** @param list<string> $blockedBy */
    public function __construct(
        public readonly TaskDefinition $definition,
        public readonly string $state,
        public readonly ?string $doneBy,
        public readonly array $blockedBy,
        public readonly ?CarbonImmutable $snoozedUntil,
        public readonly bool $moduleLocked,
        public readonly int $impact,
        public readonly float $score,
        public readonly bool $isStep,
        public readonly string $reasonCode,
    ) {}

    public function key(): string
    {
        return $this->definition->key;
    }
}
