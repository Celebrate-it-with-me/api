<?php

namespace App\Http\Services\Assistant;

use App\Models\AssistantTaskState;
use Carbon\CarbonImmutable;

/**
 * The smart assistant's brain (docs/plans/assistant-contract.md). Pure: it receives the facts of an event, the catalog,
 * what the organizer already did and "now", and answers which steps are done, due, late or waiting, and which three to
 * show first. It never reads the database, so every rule is covered by plain table tests.
 */
final class AssistantEngine
{
    public const MODE_NORMAL = 'normal';

    public const MODE_COMPRESSED = 'compressed';

    public const MODE_EVENT_PAST = 'event_past';

    public const MODE_NO_DATE = 'no_date';

    public const MODE_UNSUPPORTED = 'unsupported';

    public const STATE_DONE = 'done';

    public const STATE_NEXT = 'next';

    public const STATE_AVAILABLE = 'available';

    public const STATE_UPCOMING = 'upcoming';

    public const STATE_OVERDUE = 'overdue';

    public const STATE_HIDDEN = 'hidden';

    public const STATE_NOT_APPLICABLE = 'not_applicable';

    public const PHASES = ['foundation', 'planning', 'details', 'invitations', 'final', 'after'];

    /** Under this many months of room the plan is squeezed to the essentials. */
    public const COMPRESSED_BELOW_MONTHS = 12;

    public const MAX_STEPS = 3;

    private const SECONDS_PER_MONTH = 86400 * 30.4375;

    public function __construct(private readonly AssistantDetectors $detectors = new AssistantDetectors) {}

    /**
     * @param  list<TaskDefinition>  $catalog
     * @param  array<string, array{state: string, snoozed_until: ?CarbonImmutable}>  $states  by task key
     */
    public function evaluate(EventFacts $facts, array $catalog, array $states, CarbonImmutable $now): AssistantResult
    {
        if (! in_array($facts->eventType, ['quinceanera', 'wedding'], true)) {
            return new AssistantResult(self::MODE_UNSUPPORTED, null, null, null, [], [], 0, 0);
        }

        if ($facts->startsAt === null) {
            return new AssistantResult(self::MODE_NO_DATE, null, null, null, [], [], 0, 0);
        }

        $seconds = $facts->startsAt->getTimestamp() - $now->getTimestamp();
        $monthsLeft = $seconds / self::SECONDS_PER_MONTH;
        $daysLeft = intdiv($seconds, 86400);
        $horizon = ($facts->startsAt->getTimestamp() - $facts->createdAt->getTimestamp()) / self::SECONDS_PER_MONTH;
        $mode = match (true) {
            $monthsLeft < 0 => self::MODE_EVENT_PAST,
            $horizon < self::COMPRESSED_BELOW_MONTHS => self::MODE_COMPRESSED,
            default => self::MODE_NORMAL,
        };

        $applicable = $this->applicable($facts, $catalog);
        $impact = $this->impact($applicable);

        // Pass 1: what is settled (done by her or by the data, or "not for me"): it unlocks the steps that depend on it.
        $settled = [];
        foreach ($applicable as $def) {
            $row = $states[$def->key]['state'] ?? null;
            $settled[$def->key] = $row === AssistantTaskState::NOT_APPLICABLE
                || ($row !== AssistantTaskState::HIDDEN && ($row === AssistantTaskState::DONE || $this->detectors->evaluate($def->detector, $facts) === true));
        }

        // Pass 2: the state of each step.
        $evaluated = [];
        foreach ($applicable as $def) {
            $evaluated[$def->key] = $this->judge($def, $facts, $states[$def->key] ?? null, $settled, $monthsLeft, $horizon, $now, $applicable);
        }

        // Pass 3: pick the next steps among those that can be acted on today.
        $candidates = [];
        foreach ($evaluated as $key => $e) {
            $def = $e['def'];
            if (! in_array($e['state'], [self::STATE_OVERDUE, self::STATE_AVAILABLE], true) || $e['blockedBy'] !== [] || $e['moduleLocked']) {
                continue;
            }
            if ($mode === self::MODE_EVENT_PAST && $def->phase !== 'after') {
                continue;
            }
            $candidates[$key] = $this->score($def, $e['state'] === self::STATE_OVERDUE, $impact[$key] ?? 0, $monthsLeft, $horizon, $mode);
        }
        $order = array_flip(self::PHASES);
        uksort($candidates, function (string $a, string $b) use ($candidates, $evaluated, $order) {
            return [$candidates[$b], $order[$evaluated[$a]['def']->phase] ?? 99, $evaluated[$a]['def']->sortOrder, $a]
                <=> [$candidates[$a], $order[$evaluated[$b]['def']->phase] ?? 99, $evaluated[$b]['def']->sortOrder, $b];
        });
        $stepKeys = array_slice(array_keys($candidates), 0, self::MAX_STEPS);

        // Build the final list.
        $tasks = [];
        $done = $total = 0;
        foreach ($evaluated as $key => $e) {
            $isStep = in_array($key, $stepKeys, true);
            $state = $e['state'];
            if ($isStep && $state === self::STATE_AVAILABLE) {
                $state = self::STATE_NEXT;
            }
            $def = $e['def'];

            $counts = ! in_array($state, [self::STATE_HIDDEN, self::STATE_NOT_APPLICABLE], true) && ! $e['moduleLocked']
                && ($mode !== self::MODE_EVENT_PAST || $def->phase === 'after');
            if ($counts) {
                $total++;
                $done += $state === self::STATE_DONE ? 1 : 0;
            }

            $tasks[] = new EvaluatedTask(
                $def, $state, $e['doneBy'], $e['blockedBy'], $e['snoozedUntil'], $e['moduleLocked'], $impact[$key] ?? 0,
                round($candidates[$key] ?? 0.0, 4), $isStep, $this->reasonCode($state, $monthsLeft, $impact[$key] ?? 0, $mode, $def),
            );
        }

        return new AssistantResult($mode, round($monthsLeft, 1), $daysLeft, round($horizon, 1), $tasks, $stepKeys, $done, $total);
    }

    /**
     * The steps that apply to this event: active, of its kind of event, and whose module is not switched off.
     *
     * @param  list<TaskDefinition>  $catalog
     * @return array<string, TaskDefinition>
     */
    private function applicable(EventFacts $facts, array $catalog): array
    {
        $out = [];
        foreach ($catalog as $def) {
            if (! $def->isActive || ($def->eventType !== 'both' && $def->eventType !== $facts->eventType)) {
                continue;
            }
            if ($def->moduleKey !== null && ! isset($facts->moduleStates[$def->moduleKey])) {
                continue;
            }
            $out[$def->key] = $def;
        }

        return $out;
    }

    /**
     * How many applicable steps wait for each one.
     *
     * @param  array<string, TaskDefinition>  $applicable
     * @return array<string, int>
     */
    private function impact(array $applicable): array
    {
        $impact = [];
        foreach ($applicable as $def) {
            foreach ($def->prerequisites as $prerequisite) {
                $impact[$prerequisite] = ($impact[$prerequisite] ?? 0) + 1;
            }
        }

        return $impact;
    }

    /**
     * @param  array{state: string, snoozed_until: ?CarbonImmutable}|null  $row
     * @param  array<string, bool>  $settled
     * @param  array<string, TaskDefinition>  $applicable
     * @return array{def: TaskDefinition, state: string, doneBy: ?string, blockedBy: list<string>, snoozedUntil: ?CarbonImmutable, moduleLocked: bool}
     */
    private function judge(TaskDefinition $def, EventFacts $facts, ?array $row, array $settled, float $monthsLeft, float $horizon, CarbonImmutable $now, array $applicable): array
    {
        $locked = $def->moduleKey !== null && ($facts->moduleStates[$def->moduleKey] ?? null) === 'locked';
        $result = fn (string $state, ?string $doneBy = null, array $blockedBy = [], ?CarbonImmutable $until = null) => [
            'def' => $def, 'state' => $state, 'doneBy' => $doneBy, 'blockedBy' => $blockedBy, 'snoozedUntil' => $until, 'moduleLocked' => $locked,
        ];

        $rowState = $row['state'] ?? null;
        if ($rowState === AssistantTaskState::HIDDEN) {
            return $result(self::STATE_HIDDEN);
        }
        if ($rowState === AssistantTaskState::NOT_APPLICABLE) {
            return $result(self::STATE_NOT_APPLICABLE);
        }
        if ($rowState === AssistantTaskState::DONE) {
            return $result(self::STATE_DONE, 'user');
        }
        if ($this->detectors->evaluate($def->detector, $facts) === true) {
            return $result(self::STATE_DONE, 'data');
        }

        $until = $row['snoozed_until'] ?? null;
        if ($rowState === AssistantTaskState::SNOOZED && $until !== null && $until->greaterThan($now)) {
            return $result(self::STATE_UPCOMING, null, [], $until);
        }

        // A prerequisite that does not apply to this event (other kind of event, switched-off module) never blocks.
        $blockedBy = array_values(array_filter($def->prerequisites, fn (string $key) => isset($applicable[$key]) && ! ($settled[$key] ?? false)));
        if ($blockedBy !== []) {
            return $result($monthsLeft <= $def->startMonths ? self::STATE_AVAILABLE : self::STATE_UPCOMING, null, $blockedBy);
        }

        // If she created the event when this step was already late, she is not scolded: it is simply available.
        if ($monthsLeft < $def->lateMonths && $horizon > $def->lateMonths) {
            return $result(self::STATE_OVERDUE);
        }
        if ($monthsLeft > $def->startMonths) {
            return $result(self::STATE_UPCOMING);
        }

        return $result(self::STATE_AVAILABLE);
    }

    private function score(TaskDefinition $def, bool $overdue, int $impact, float $monthsLeft, float $horizon, string $mode): float
    {
        $start = $mode === self::MODE_COMPRESSED ? min($def->startMonths, $horizon) : $def->startMonths;
        $urgency = max(0.0, min(1.0, ($start - $monthsLeft) / max($start - $def->lateMonths, 0.5)));

        return 100 * $urgency + 10 * $impact + ($overdue ? 50 : 0);
    }

    private function reasonCode(string $state, float $monthsLeft, int $impact, string $mode, TaskDefinition $def): string
    {
        return match (true) {
            $monthsLeft >= 0 && $monthsLeft < 1 && $def->phase !== 'after' => 'event_close',
            $state === self::STATE_OVERDUE => 'overdue',
            $mode === self::MODE_COMPRESSED => 'compressed',
            $impact >= 2 => 'unlocks',
            default => 'time_window',
        };
    }
}
