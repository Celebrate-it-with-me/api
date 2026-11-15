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
        'overdue' => ['es' => 'Lo ideal era hacerlo {late} meses antes; mejor hoy que mañana.', 'en' => 'The ideal time was {late} months out; better today than tomorrow.'],
        'unlocks' => ['es' => 'De esto dependen otras {unlocks} cosas.', 'en' => '{unlocks} other things depend on this.'],
        'compressed' => ['es' => 'Tu fecha está cerca: nos quedamos con lo esencial.', 'en' => 'Your date is close, so we kept it to the essentials.'],
        'event_close' => ['es' => 'Faltan {days} días.', 'en' => '{days} days to go.'],
    ];

    /** Plural word => singular, applied to a sentence whose count is exactly 1. */
    private const SINGULAR = [
        'es' => ['Faltan' => 'Falta', 'meses' => 'mes', 'días' => 'día', 'cosas' => 'cosa', 'otras' => 'otra', 'invitados' => 'invitado', 'salen' => 'sale', 'dependen' => 'depende'],
        'en' => ['months' => 'month', 'days' => 'day', 'things' => 'thing', 'guests' => 'guest', 'depend' => 'depends', "haven't" => "hasn't"],
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
            'late' => $this->number($this->roundMonths($context['late']), $lang),
            'guests' => $context['guests'] > 0 ? $context['guests'] : null,
            'done' => $context['done'],
        ];
        $raw = [
            'months' => $context['months'] === null ? null : $this->roundMonths($context['months']),
            'days' => $context['days'], 'unlocks' => $values['unlocks'], 'late' => $this->roundMonths($context['late']),
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
                $sentence = $this->agree($sentence, $markers, $raw, $lang);
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

    /**
     * Singular/plural/zero agreement for a sentence that counts something: "1 día" and "Falta", never "1 días" and
     * "Faltan", and "menos de un mes" instead of "0 meses". Texts the admin edited keep working: the rule keys off the
     * unit word that follows the marker, not off the catalog wording.
     *
     * @param  array<int, string>  $markers
     * @param  array<string, mixed>  $raw
     */
    private function agree(string $sentence, array $markers, array $raw, string $lang): string
    {
        foreach ($markers as $marker) {
            $value = $raw[$marker] ?? null;
            if (! in_array($marker, ['months', 'late', 'days', 'unlocks', 'guests'], true) || ! is_numeric($value)) {
                continue;
            }
            if (in_array($marker, ['months', 'late'], true) && (float) $value === 0.0) {
                $zero = $lang === 'es'
                    ? ['/Faltan \{' . $marker . '\} meses/u' => 'Falta menos de un mes', '/\{' . $marker . '\} meses/u' => 'menos de un mes']
                    : ['/\{' . $marker . '\} months/u' => 'less than a month'];
                $sentence = preg_replace(array_keys($zero), array_values($zero), $sentence) ?? $sentence;

                continue;
            }
            if (abs((float) $value) !== 1.0) {
                continue;
            }
            if ($marker === 'unlocks' && $lang === 'es') {
                $sentence = str_replace('otras {unlocks} cosas', 'otra cosa', $sentence);
            }
            foreach (self::SINGULAR[$lang] as $plural => $singular) {
                $sentence = preg_replace('/(?<![\p{L}\'{])' . preg_quote($plural, '/') . '(?![\p{L}])/u', $singular, $sentence) ?? $sentence;
            }
        }

        return $sentence;
    }

    /** One convention per language: comma in Spanish, point in English, one decimal under 10. */
    private function months(float $months, string $lang): string
    {
        return $this->number($this->roundMonths($months), $lang);
    }

    private function number(int|float $value, string $lang = 'en'): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');

        return $lang === 'es' ? str_replace('.', ',', $text) : $text;
    }
}
