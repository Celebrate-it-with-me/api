<?php

namespace App\Http\Services\AppServices\Invitation;

/**
 * Reads the `bind` of an `editable.json` field: the only place that knows where in `events.template_config` each field
 * lives. The app never builds a config; it sends values by field id and this class turns them into config-shaped
 * fragments (`null` means "back to the template's value"), merges them, prunes what a version no longer knows and
 * computes the effective value of each field.
 *
 * A fragment has the shape `EventTemplateConfigValidator` validates: `sections` is a list of `{type, ...}`.
 */
final class EditableBinding
{
    /** Keys of a section that belong to the organizer, not to a package field. */
    private const OWN_SECTION_KEYS = ['enabled', 'order'];

    /** The config of one field value. @param array<string, mixed> $field @return array<string, mixed> */
    public function fragment(array $field, mixed $value): array
    {
        $bind = (array) ($field['bind'] ?? []);

        if (isset($bind['section'], $bind['field'])) {
            return ['sections' => [['type' => $bind['section'], $bind['field'] => $value]]];
        }
        if (isset($bind['section'], $bind['setting'])) {
            return ['sections' => [['type' => $bind['section'], 'settings' => [$bind['setting'] => $value]]]];
        }
        if (isset($bind['theme'])) {
            return ['theme' => $this->nest(explode('.', (string) $bind['theme']), $value)];
        }
        if (isset($bind['option'])) {
            return ['package' => ['options' => [(string) $bind['option'] => $value]]];
        }

        return [];
    }

    /** The `focus` of a photo field. @param array<string, mixed> $field @return array<string, mixed> */
    public function focusFragment(array $field, mixed $value): array
    {
        $bind = (array) ($field['bind'] ?? []);

        return isset($bind['section'], $bind['setting'])
            ? ['sections' => [['type' => $bind['section'], 'settings' => [(string) ($bind['focus'] ?? 'focus') => $value]]]]
            : [];
    }

    /** @param list<string> $path */
    private function nest(array $path, mixed $value): mixed
    {
        $first = array_shift($path);

        return [$first => $path === [] ? $value : $this->nest($path, $value)];
    }

    /** A fragment without its `null` leaves: what there is to validate (a reset has nothing to check). */
    public function withoutNulls(array $fragment): array
    {
        return $this->strip($fragment, false);
    }

    /**
     * Merges a fragment into a config; `null` leaves delete what they point to. Empty branches are dropped and a
     * section left with only its `type` disappears.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $fragment
     * @return array<string, mixed>
     */
    public function merge(array $config, array $fragment): array
    {
        $bySection = [];
        foreach ((array) ($config['sections'] ?? []) as $section) {
            if (is_array($section) && is_string($section['type'] ?? null)) {
                $bySection[$section['type']] = $section;
            }
        }
        foreach ((array) ($fragment['sections'] ?? []) as $section) {
            $type = $section['type'];
            $bySection[$type] = $this->deep($bySection[$type] ?? ['type' => $type], $section);
        }

        $out = $config;
        unset($out['sections']);
        foreach (['theme', 'package'] as $key) {
            if (array_key_exists($key, $fragment)) {
                $out[$key] = $this->deep((array) ($config[$key] ?? []), (array) $fragment[$key]);
            }
        }
        $sections = array_values(array_filter(array_map(fn (array $s) => $this->strip($s, true), $bySection), fn (array $s) => array_diff(array_keys($s), ['type']) !== []));
        if ($sections !== []) {
            $out['sections'] = $sections;
        }

        return $this->compact($out);
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $patch @return array<string, mixed> */
    private function deep(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
            } elseif (is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->deep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function compact(array $value): array
    {
        return $this->strip($value, true);
    }

    /** @return array<string, mixed> */
    private function strip(array $value, bool $emptyToo): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = array_is_list($item) && $item !== [] && is_array($item[0] ?? null) && isset($item[0]['type'])
                    ? array_map(fn ($i) => $this->strip($i, $emptyToo), $item)
                    : $this->strip($item, $emptyToo);
                if ($emptyToo && $item === []) {
                    continue;
                }
            }
            if ($item === null) {
                continue;
            }
            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * Keeps only what the fields of a version know how to place: the organizer's own section controls
     * (`enabled`, `order`), and the values of the bound fields. The rest is returned apart (it is not lost: the editor
     * keeps it in the stash).
     *
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $fields
     * @param  bool  $keepContent  keep every section value (the content belongs to the event and survives a template
     *                             that has no field for it); `false` is the strict copy the validator is run on
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} kept, pruned
     */
    public function prune(array $config, array $fields, bool $keepContent = true): array
    {
        $kept = [];
        $pruned = [];

        foreach ((array) ($config['sections'] ?? []) as $section) {
            if (! is_array($section) || ! is_string($section['type'] ?? null)) {
                continue;
            }
            $keep = ['type' => $section['type']];
            $drop = ['type' => $section['type']];
            foreach ($section as $key => $value) {
                if ($key === 'type') {
                    continue;
                }
                if ($keepContent || in_array($key, self::OWN_SECTION_KEYS, true)) {
                    $keep[$key] = $value;
                } elseif ($key === 'settings' && is_array($value)) {
                    foreach ($value as $setting => $v) {
                        $this->route($this->isBoundSetting($fields, $section['type'], (string) $setting), $keep, $drop, ['settings', $setting], $v);
                    }
                } else {
                    $this->route($this->isBoundField($fields, $section['type'], (string) $key), $keep, $drop, [$key], $value);
                }
            }
            $kept['sections'][] = $keep;
            if (array_diff(array_keys($drop), ['type']) !== []) {
                $pruned['sections'][] = $drop;
            }
        }

        foreach ((array) ($config['theme'] ?? []) as $key => $value) {
            if ($key === 'preset') {
                $this->routeTheme($this->hasBind($fields, 'theme', 'preset'), $kept, $pruned, ['preset'], $value);
            } elseif (is_array($value)) {
                foreach ($value as $name => $v) {
                    $this->routeTheme($this->hasBind($fields, 'theme', "{$key}.{$name}"), $kept, $pruned, [$key, $name], $v);
                }
            }
        }
        foreach ((array) ($config['package']['options'] ?? []) as $id => $value) {
            $this->routeTheme($this->hasBind($fields, 'option', (string) $id), $kept, $pruned, ['options', $id], $value, 'package');
        }

        return [$this->merge([], $kept), $this->merge([], $pruned)];
    }

    /** @param array<string, mixed> $keep @param array<string, mixed> $drop @param list<string|int> $path */
    private function route(bool $known, array &$keep, array &$drop, array $path, mixed $value): void
    {
        $target = &$keep;
        if (! $known) {
            $target = &$drop;
        }
        $node = &$target;
        foreach ($path as $i => $step) {
            $node[$step] ??= [];
            if ($i === count($path) - 1) {
                $node[$step] = $value;
            } else {
                $node = &$node[$step];
            }
        }
    }

    /** @param array<string, mixed> $kept @param array<string, mixed> $pruned @param list<string|int> $path */
    private function routeTheme(bool $known, array &$kept, array &$pruned, array $path, mixed $value, string $root = 'theme'): void
    {
        $target = &$kept;
        if (! $known) {
            $target = &$pruned;
        }
        $node = &$target[$root];
        foreach ($path as $i => $step) {
            if ($i === count($path) - 1) {
                $node[$step] = $value;
            } else {
                $node[$step] ??= [];
                $node = &$node[$step];
            }
        }
    }

    /** @param list<array<string, mixed>> $fields */
    private function hasBind(array $fields, string $kind, string $value): bool
    {
        foreach ($fields as $field) {
            if (($field['bind'][$kind] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $fields */
    private function isBoundField(array $fields, string $section, string $name): bool
    {
        foreach ($fields as $field) {
            if (($field['bind']['section'] ?? null) === $section && ($field['bind']['field'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $fields */
    private function isBoundSetting(array $fields, string $section, string $name): bool
    {
        foreach ($fields as $field) {
            if (($field['bind']['section'] ?? null) !== $section) {
                continue;
            }
            if (($field['bind']['setting'] ?? null) === $name || (isset($field['bind']['setting']) && $name === ($field['bind']['focus'] ?? null))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drops what the free plan may not have: the values of the fields marked `plan: full`. A draft that lost its plan
     * (a refund) is drawn without them (D-4).
     *
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public function withoutFullPlan(array $config, array $fields): array
    {
        foreach ($fields as $field) {
            if (($field['plan'] ?? 'free') === 'full') {
                $config = $this->merge($config, $this->fragment($field, null));
            }
        }

        return $config;
    }
}
