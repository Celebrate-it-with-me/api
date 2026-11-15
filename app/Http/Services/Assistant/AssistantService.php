<?php

namespace App\Http\Services\Assistant;

use App\Models\AssistantCustomTask;
use App\Models\AssistantTask;
use App\Models\AssistantTaskState;
use App\Models\Events;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** The assistant seen from the API: runs the engine for an event, shapes the answers and records what the organizer does. */
class AssistantService
{
    public const DEFAULT_SNOOZE_DAYS = 14;

    public const PHASE_LABELS = [
        'es' => ['foundation' => 'Primeros pasos', 'planning' => 'Reservas', 'details' => 'Detalles', 'invitations' => 'Invitaciones', 'final' => 'Recta final', 'after' => 'Después'],
        'en' => ['foundation' => 'First steps', 'planning' => 'Bookings', 'details' => 'Details', 'invitations' => 'Invitations', 'final' => 'Final stretch', 'after' => 'Afterwards'],
    ];

    public function __construct(
        private readonly AssistantFactsLoader $loader,
        private readonly AssistantEngine $engine,
        private readonly AssistantReasons $reasons,
    ) {}

    /** @return array{0: EventFacts, 1: AssistantResult} */
    public function run(Events $event, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $facts = $this->loader->load($event, $now);

        $catalog = AssistantTask::query()->where('is_active', true)->orderBy('id')->get()->map(fn (AssistantTask $t) => TaskDefinition::fromModel($t))->all();

        $states = [];
        foreach (AssistantTaskState::query()->where('event_id', $event->id)->get() as $row) {
            $states[$row->task_key] = ['state' => $row->state, 'snoozed_until' => $row->snoozed_until ? CarbonImmutable::instance($row->snoozed_until) : null];
        }

        return [$facts, $this->engine->evaluate($facts, $catalog, $states, $now)];
    }

    /** @return array<string, mixed> */
    public function next(Events $event, string $lang): array
    {
        [$facts, $result] = $this->run($event);

        return $this->header($result) + [
            'steps' => array_map(fn (string $key) => $this->taskPayload($result->task($key), $result, $facts, $lang), $result->stepKeys),
            'allSet' => $result->allSet(),
            'progress' => $this->progress($result),
        ];
    }

    /** @return array<string, mixed> */
    public function plan(Events $event, string $lang): array
    {
        [$facts, $result] = $this->run($event);

        $phases = $result->mode === AssistantEngine::MODE_EVENT_PAST ? ['after'] : AssistantEngine::PHASES;
        $payload = fn (EvaluatedTask $t) => $this->taskPayload($t, $result, $facts, $lang);
        $visible = array_filter($result->tasks, fn (EvaluatedTask $t) => in_array($t->definition->phase, $phases, true));

        $groups = [];
        foreach ($phases as $phase) {
            $mine = array_values(array_filter($visible, fn (EvaluatedTask $t) => $t->definition->phase === $phase
                && ! in_array($t->state, [AssistantEngine::STATE_HIDDEN, AssistantEngine::STATE_NOT_APPLICABLE], true)));
            usort($mine, fn (EvaluatedTask $a, EvaluatedTask $b) => [$a->state === AssistantEngine::STATE_DONE, $a->definition->sortOrder] <=> [$b->state === AssistantEngine::STATE_DONE, $b->definition->sortOrder]);
            $groups[] = ['key' => $phase, 'label' => self::PHASE_LABELS[$lang][$phase], 'tasks' => array_map($payload, $mine)];
        }

        return $this->header($result) + [
            'progress' => $this->progress($result),
            'phases' => $groups,
            'overdue' => array_values(array_map($payload, array_filter($visible, fn (EvaluatedTask $t) => $t->state === AssistantEngine::STATE_OVERDUE))),
            'hidden' => array_values(array_map($payload, array_filter($visible, fn (EvaluatedTask $t) => in_array($t->state, [AssistantEngine::STATE_HIDDEN, AssistantEngine::STATE_NOT_APPLICABLE], true)))),
            'custom' => AssistantCustomTask::query()->where('event_id', $event->id)->orderBy('id')->get()->map(fn (AssistantCustomTask $t) => $this->customPayload($t))->all(),
        ];
    }

    /**
     * Record what the organizer did with a step and answer with the step as it is now. `reset` forgets it.
     * Returns null when the key is not a step of this event (unknown, switched off, or of the other kind of event).
     *
     * @return array<string, mixed>|null
     */
    public function act(Events $event, User $user, string $key, string $action, string $lang, ?int $days = null): ?array
    {
        [, $before] = $this->run($event);
        if (! $before->task($key)) {
            return null;
        }

        $attributes = match ($action) {
            'done' => ['state' => AssistantTaskState::DONE, 'snoozed_until' => null],
            'hide' => ['state' => AssistantTaskState::HIDDEN, 'snoozed_until' => null],
            'not_applicable' => ['state' => AssistantTaskState::NOT_APPLICABLE, 'snoozed_until' => null],
            'snooze' => ['state' => AssistantTaskState::SNOOZED, 'snoozed_until' => CarbonImmutable::now()->addDays($days ?? self::DEFAULT_SNOOZE_DAYS)],
            default => null,
        };

        if ($action === 'reset') {
            AssistantTaskState::query()->where('event_id', $event->id)->where('task_key', $key)->delete();
        } elseif ($attributes !== null) {
            AssistantTaskState::query()->updateOrCreate(
                ['event_id' => $event->id, 'task_key' => $key],
                $attributes + ['set_by_user_id' => $user->id],
            );
        }

        [$facts, $after] = $this->run($event);

        return $this->taskPayload($after->task($key), $after, $facts, $lang);
    }

    public function createCustom(Events $event, User $user, array $data): ?AssistantCustomTask
    {
        if (AssistantCustomTask::query()->where('event_id', $event->id)->count() >= AssistantCustomTask::MAX_PER_EVENT) {
            return null;
        }

        return AssistantCustomTask::query()->create([
            'event_id' => $event->id,
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'created_by_user_id' => $user->id,
        ]);
    }

    /** @param array<string, mixed> $data only the keys present are changed; `done` is a boolean */
    public function updateCustom(AssistantCustomTask $task, array $data): AssistantCustomTask
    {
        foreach (['title', 'notes', 'due_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $task->{$field} = $data[$field];
            }
        }
        if (array_key_exists('done', $data)) {
            $task->done_at = $data['done'] ? ($task->done_at ?? CarbonImmutable::now()) : null;
        }
        $task->save();

        return $task;
    }

    /** @return array<string, mixed> */
    public function customPayload(AssistantCustomTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'notes' => $task->notes,
            'dueDate' => $task->due_date?->toDateString(),
            'doneAt' => $this->iso($task->done_at),
            'createdAt' => $this->iso($task->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public function taskPayload(EvaluatedTask $task, AssistantResult $result, EventFacts $facts, string $lang): array
    {
        $def = $task->definition;
        $reason = $this->reasons->render($task, $lang, [
            'months' => $result->monthsLeft,
            'days' => $result->daysLeft,
            'unlocks' => $task->impact,
            'late' => $def->lateMonths,
            'guests' => $facts->pendingGuests,
            'done' => $result->progressDone,
        ]);

        return [
            'key' => $def->key,
            'phase' => $def->phase,
            'title' => $def->title($lang),
            'state' => $task->state,
            'reason' => $reason['reason'],
            'reasonCode' => $task->reasonCode,
            'reasonParams' => (object) $reason['params'],
            'route' => $def->route,
            'moduleKey' => $def->moduleKey,
            'moduleLocked' => $task->moduleLocked,
            'doneBy' => $task->doneBy,
            'blockedBy' => $task->blockedBy,
            'snoozedUntil' => $this->iso($task->snoozedUntil),
            'startMonths' => $def->startMonths,
            'lateMonths' => $def->lateMonths,
        ];
    }

    /** @return array<string, mixed> */
    private function header(AssistantResult $result): array
    {
        return ['mode' => $result->mode, 'monthsLeft' => $result->monthsLeft, 'daysLeft' => $result->daysLeft];
    }

    /** @return array{done: int, total: int, ratio: float} */
    private function progress(AssistantResult $result): array
    {
        return ['done' => $result->progressDone, 'total' => $result->progressTotal, 'ratio' => $result->ratio()];
    }

    private function iso(?CarbonInterface $date): ?string
    {
        return $date ? CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
