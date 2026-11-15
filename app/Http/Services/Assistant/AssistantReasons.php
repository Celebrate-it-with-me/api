<?php

namespace App\Http\Services\Assistant;

/**
 * The "why now" of a step, already translated: the step's own text plus a sentence of context for its reason code.
 * A marker without a value ({unlocks} with nothing depending on the step, say) takes its whole sentence with it, so
 * a `{x}` never reaches the organizer.
 */
final class AssistantReasons
{
    private const CONTEXT = [
        'time_window' => ['es' => 'Faltan {months} meses y este es el momento ideal.', 'en' => '{months} months to go, and now is the ideal time.'],
        'overdue' => ['es' => 'Ya pasó el mejor momento ({late} meses antes); mejor hoy que mañana.', 'en' => 'The ideal time has passed ({late} months out); better today than tomorrow.'],
        'unlocks' => ['es' => 'De aquí salen otras {unlocks} cosas.', 'en' => '{unlocks} other things depend on this.'],
        'compressed' => ['es' => 'Tu fecha está cerca, así que lo juntamos todo en lo esencial.', 'en' => 'Your date is close, so we kept it to the essentials.'],
        'event_close' => ['es' => 'Faltan {days} días.', 'en' => '{days} days to go.'],
    ];

    /**
     * @param  array{months: ?float, days: ?int, unlocks: int, late: float, guests: int, done: int}  $context
     * @return array{reason: string, params: array<string, int|float>}
     */
    public function render(EvaluatedTask $task, string $lang, array $context): array
    {
        $lang = $lang === 'en' ? 'en' : 'es';
        $why = $task->definition->why($lang);
        $code = $task->reasonCode;

        $parts = [$why];
        $past = $task->definition->phase === 'after';
        // The unlocks line would repeat what the step's own text already says.
        $repeats = $code === 'unlocks' && str_contains($why, '{unlocks}');
        if (! $repeats && ! ($code === 'time_window' && $past)) {
            $parts[] = self::CONTEXT[$code][$lang];
        }
        if ($code !== 'unlocks' && $task->impact >= 2 && ! str_contains($why, '{unlocks}')) {
            $parts[] = self::CONTEXT['unlocks'][$lang];
        }

        $values = [
            'months' => $context['months'] === null ? null : $this->months($context['months'], $lang),
            'days' => $context['days'],
            'unlocks' => $context['unlocks'] > 0 ? $context['unlocks'] : null,
            'late' => $this->number($context['late']),
            'guests' => $context['guests'] > 0 ? $context['guests'] : null,
            'done' => $context['done'],
        ];
        $raw = [
            'months' => $context['months'] === null ? null : $this->roundMonths($context['months']),
            'days' => $context['days'], 'unlocks' => $values['unlocks'], 'late' => round($context['late'], 1),
            'guests' => $values['guests'], 'done' => $context['done'],
        ];

        $used = [];
        $sentences = [];
        foreach ($parts as $part) {
            foreach (preg_split('/(?<=[.!?])\s+/u', trim($part)) ?: [] as $sentence) {
                if ($sentence === '') {
                    continue;
                }
                preg_match_all('/\{(\w+)\}/', $sentence, $m);
                $markers = array_unique($m[1]);
                foreach ($markers as $marker) {
                    if (($values[$marker] ?? null) === null) {
                        continue 2;
                    }
                }
                foreach ($markers as $marker) {
                    $sentence = str_replace('{' . $marker . '}', (string) $values[$marker], $sentence);
                    $used[$marker] = $raw[$marker];
                }
                $sentences[] = $sentence;
            }
        }

        $reason = trim(implode(' ', $sentences));

        return ['reason' => $reason !== '' ? $reason : $task->definition->title($lang), 'params' => $used];
    }

    public function roundMonths(float $months): int|float
    {
        $abs = abs($months);

        return $abs < 10 ? round($months, 1) : (int) round($months);
    }

    private function months(float $months, string $lang): string
    {
        $text = $this->number($this->roundMonths($months));

        return $lang === 'es' ? str_replace('.', ',', $text) : $text;
    }

    private function number(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }
}
