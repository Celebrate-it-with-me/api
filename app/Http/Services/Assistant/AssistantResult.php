<?php

namespace App\Http\Services\Assistant;

/** What the engine answers for one event: the mode, the clock and every applicable step evaluated. */
final class AssistantResult
{
    /**
     * @param  list<EvaluatedTask>  $tasks  every step that applies to the event, in catalog order (hidden and "not for me" included)
     * @param  list<string>  $stepKeys  the next steps, by priority (0..3)
     */
    public function __construct(
        public readonly string $mode,
        public readonly ?float $monthsLeft,
        public readonly ?int $daysLeft,
        public readonly ?float $horizonMonths,
        public readonly array $tasks,
        public readonly array $stepKeys,
        public readonly int $progressDone,
        public readonly int $progressTotal,
    ) {}

    public function task(string $key): ?EvaluatedTask
    {
        foreach ($this->tasks as $task) {
            if ($task->key() === $key) {
                return $task;
            }
        }

        return null;
    }

    public function ratio(): float
    {
        return $this->progressTotal > 0 ? round($this->progressDone / $this->progressTotal, 2) : 0.0;
    }

    /** No steps left and at least one done. */
    public function allSet(): bool
    {
        return $this->stepKeys === [] && $this->progressDone > 0;
    }
}
