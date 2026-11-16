<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\InvitationTemplateVersion;
use App\Models\TemplatePackage;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * Validates what an organizer saves in `events.template_config` against the `editable.json` of the package her event is
 * fixed to (or, while it is a draft, the one it follows): only fields the package exposes, with the right type and
 * limits, and `plan: full` fields only on an unlocked event. The endpoint that saves it belongs to the "Tu invitación"
 * editor; this class is its contract (docs/plans/template-package-spec.md, 3.4).
 *
 * Errors: 422 `errors.<path>[0]` = unknown_field | invalid_type | invalid_color | too_long | invalid_choice |
 * invalid_focus; 403 `{code: 'plan_required'}`.
 */
class EventTemplateConfigValidator
{
    private const HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    /** @var array<string, string> */
    private array $errors = [];

    /** @var list<array<string, mixed>> fields the config touches */
    private array $touched = [];

    /** The package an event draws, or null when it uses the engine's own components. */
    public static function packageFor(Events $event): ?TemplatePackage
    {
        $versionId = $event->template_version_id ?? $event->template?->current_version_id;

        return $versionId ? InvitationTemplateVersion::query()->with('package')->find($versionId)?->package : null;
    }

    /**
     * @param  array<string, mixed>  $config  the `template_config` to save
     * @param  bool  $unlocked  whether the event is unlocked (`PurchaseService::isUnlocked`)
     *
     * @throws ValidationException
     * @throws HttpResponseException
     */
    public function validate(array $config, TemplatePackage $package, bool $unlocked): void
    {
        $this->errors = [];
        $this->touched = [];
        $fields = (array) ($package->editable['fields'] ?? []);

        foreach (array_diff(array_keys($config), ['sections', 'theme', 'package']) as $key) {
            $this->errors[(string) $key] = 'unknown_field';
        }
        $this->sections($config['sections'] ?? [], $fields);
        $this->theme($config['theme'] ?? [], $fields);
        $this->options($config['package'] ?? [], $fields);

        if ($this->errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $code) => [$code], $this->errors));
        }

        if (! $unlocked && collect($this->touched)->contains(fn (array $f) => ($f['plan'] ?? 'free') === 'full')) {
            throw new HttpResponseException(response()->json([
                'message' => 'Esta personalización es parte del evento completo.',
                'code' => 'plan_required',
            ], 403));
        }
    }

    /** @param array<int, mixed> $fields */
    private function sections(mixed $sections, array $fields): void
    {
        if (! is_array($sections) || ($sections !== [] && ! array_is_list($sections))) {
            $this->errors['sections'] = 'invalid_type';

            return;
        }
        foreach ($sections as $i => $section) {
            $at = "sections.{$i}";
            if (! is_array($section) || ! is_string($section['type'] ?? null)) {
                $this->errors[$at] = 'invalid_type';

                continue;
            }
            foreach (array_diff(array_keys($section), ['type', 'enabled', 'order', 'title', 'text', 'settings']) as $key) {
                $this->errors["{$at}.{$key}"] = 'unknown_field';
            }
            // Visibility and order of sections are the organizer's own controls, not a package field.
            if (isset($section['enabled']) && ! is_bool($section['enabled'])) {
                $this->errors["{$at}.enabled"] = 'invalid_type';
            }
            if (isset($section['order']) && (! is_int($section['order']) || $section['order'] < 0 || $section['order'] > 99)) {
                $this->errors["{$at}.order"] = 'invalid_type';
            }

            foreach (['title', 'text'] as $key) {
                if (array_key_exists($key, $section)) {
                    $this->text($section[$key], $this->find($fields, fn ($f) => ($f['bind']['section'] ?? null) === $section['type'] && ($f['bind']['field'] ?? null) === $key), "{$at}.{$key}");
                }
            }
            if (array_key_exists('settings', $section)) {
                $this->settings($section['settings'], $section['type'], $fields, "{$at}.settings");
            }
        }
    }

    private function settings(mixed $settings, string $type, array $fields, string $at): void
    {
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            $this->errors[$at] = 'invalid_type';

            return;
        }
        foreach ($settings as $key => $value) {
            $field = $this->find($fields, fn ($f) => ($f['bind']['section'] ?? null) === $type
                && (($f['bind']['setting'] ?? null) === $key || ($key === 'focus' && ($f['bind']['focus'] ?? null) === 'focus')));
            if ($field === null) {
                $this->errors["{$at}.{$key}"] = 'unknown_field';

                continue;
            }
            $this->touched[] = $field;
            if ($key === 'focus') {
                if ($value !== null && (! is_string($value) || ! preg_match('/^\d{1,3}% \d{1,3}%$/', $value))) {
                    $this->errors["{$at}.focus"] = 'invalid_focus';
                }
            } elseif ($value !== null && (! is_string($value) || strlen($value) > 255 || ! preg_match('#^/(?!/)[A-Za-z0-9._~/-]*$#', $value) || str_contains($value, '..'))) {
                // Photos are stored by the app: a relative path, never an address on another site.
                $this->errors["{$at}.{$key}"] = 'invalid_type';
            }
        }
    }

    private function text(mixed $value, ?array $field, string $at): void
    {
        if ($field === null) {
            $this->errors[$at] = 'unknown_field';

            return;
        }
        $this->touched[] = $field;
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            $this->errors[$at] = 'invalid_type';

            return;
        }
        foreach ($value as $lang => $text) {
            if (! in_array($lang, ['es', 'en'], true)) {
                $this->errors["{$at}.{$lang}"] = 'unknown_field';
            } elseif ($text !== null && (! is_string($text) || preg_match('/<\/?[a-zA-Z!][^>]*>/', $text) === 1)) {
                $this->errors["{$at}.{$lang}"] = 'invalid_type';
            } elseif (is_string($text) && mb_strlen($text) > min(1000, (int) ($field['max'][$lang] ?? 1000))) {
                $this->errors["{$at}.{$lang}"] = 'too_long';
            }
        }
    }

    private function theme(mixed $theme, array $fields): void
    {
        if (! is_array($theme) || ($theme !== [] && array_is_list($theme))) {
            $this->errors['theme'] = 'invalid_type';

            return;
        }
        foreach (array_diff(array_keys($theme), ['preset', 'colors', 'fonts']) as $key) {
            $this->errors["theme.{$key}"] = 'unknown_field';
        }

        if (array_key_exists('preset', $theme)) {
            $field = $this->find($fields, fn ($f) => ($f['bind']['theme'] ?? null) === 'preset');
            $this->choice($theme['preset'], $field, 'theme.preset');
        }
        foreach (['colors' => 'color', 'fonts' => 'font'] as $group => $kind) {
            if (! array_key_exists($group, $theme)) {
                continue;
            }
            if (! is_array($theme[$group]) || ($theme[$group] !== [] && array_is_list($theme[$group]))) {
                $this->errors["theme.{$group}"] = 'invalid_type';

                continue;
            }
            foreach ($theme[$group] as $key => $value) {
                $at = "theme.{$group}.{$key}";
                $field = $this->find($fields, fn ($f) => ($f['bind']['theme'] ?? null) === "{$group}.{$key}");
                if ($field === null) {
                    $this->errors[$at] = 'unknown_field';
                } elseif ($kind === 'color') {
                    $this->touched[] = $field;
                    if (! is_string($value) || ! preg_match(self::HEX, $value)) {
                        $this->errors[$at] = 'invalid_color';
                    }
                } else {
                    $this->choice($value, $field, $at);
                }
            }
        }
    }

    private function options(mixed $package, array $fields): void
    {
        if (! is_array($package) || ($package !== [] && array_is_list($package))) {
            $this->errors['package'] = 'invalid_type';

            return;
        }
        foreach (array_diff(array_keys($package), ['options']) as $key) {
            $this->errors["package.{$key}"] = 'unknown_field';
        }
        $options = $package['options'] ?? [];
        if (! is_array($options) || ($options !== [] && array_is_list($options))) {
            $this->errors['package.options'] = 'invalid_type';

            return;
        }
        foreach ($options as $id => $value) {
            $at = "package.options.{$id}";
            $field = $this->find($fields, fn ($f) => ($f['bind']['option'] ?? null) === $id);
            if ($field === null) {
                $this->errors[$at] = 'unknown_field';
            } elseif (($field['type'] ?? null) === 'toggle') {
                $this->touched[] = $field;
                if (! is_bool($value)) {
                    $this->errors[$at] = 'invalid_type';
                }
            } else {
                $this->choice($value, $field, $at);
            }
        }
    }

    /** A value among the `choices` of a field. */
    private function choice(mixed $value, ?array $field, string $at): void
    {
        if ($field === null) {
            $this->errors[$at] = 'unknown_field';

            return;
        }
        $this->touched[] = $field;
        $allowed = array_column((array) ($field['choices'] ?? []), 'value');
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $this->errors[$at] = 'invalid_choice';
        }
    }

    /** @param array<int, mixed> $fields */
    private function find(array $fields, callable $match): ?array
    {
        foreach ($fields as $field) {
            if (is_array($field) && $match($field)) {
                return $field;
            }
        }

        return null;
    }
}
