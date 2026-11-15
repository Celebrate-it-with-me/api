<?php

namespace App\Http\Services\Assistant;

use App\Models\AssistantTask;

/** One step of the catalog, detached from the database so the engine can run on plain data. */
final class TaskDefinition
{
    /** @param list<string> $prerequisites */
    public function __construct(
        public readonly string $key,
        public readonly string $eventType,
        public readonly string $phase,
        public readonly int $sortOrder,
        public readonly float $startMonths,
        public readonly float $lateMonths,
        public readonly array $prerequisites,
        public readonly ?string $detector,
        public readonly ?string $moduleKey,
        public readonly string $route,
        public readonly string $titleEs,
        public readonly string $titleEn,
        public readonly string $whyEs,
        public readonly string $whyEn,
        public readonly bool $isActive = true,
    ) {}

    public static function fromModel(AssistantTask $task): self
    {
        return new self(
            $task->key, $task->event_type, $task->phase, (int) $task->sort_order, (float) $task->start_months, (float) $task->late_months,
            array_values($task->prerequisites ?? []), $task->detector, $task->module_key, $task->route,
            $task->title_es, $task->title_en, $task->why_es, $task->why_en, (bool) $task->is_active,
        );
    }

    /** From a row of AssistantTaskSeeder::CATALOG (used by the pure engine tests). */
    public static function fromCatalogRow(string $key, array $row): self
    {
        [$type, $phase, $order, $start, $late, $prerequisites, $detector, $module, $route, $titleEs, $titleEn, $whyEs, $whyEn] = $row;

        return new self($key, $type, $phase, $order, (float) $start, (float) $late, $prerequisites, $detector, $module, $route, $titleEs, $titleEn, $whyEs, $whyEn);
    }

    public function title(string $lang): string
    {
        return $lang === 'en' ? $this->titleEn : $this->titleEs;
    }

    public function why(string $lang): string
    {
        return $lang === 'en' ? $this->whyEn : $this->whyEs;
    }
}
