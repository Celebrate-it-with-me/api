<?php

namespace App\Http\Services\Admin\Templates;

use Illuminate\Validation\ValidationException;

/**
 * Strict validator for the content of a template version (docs/plans/admin-templates.md, point 7). Nothing the
 * public renderer cannot draw gets in: no HTML, no free CSS, hex colors, fonts from config/invitation.php, relative
 * images, the section catalog, cover and rsvp always on. Errors come per field (`sections.2.title.es`).
 */
class TemplateSchema
{
    private const HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    private const PRESET_ID = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @throws ValidationException
     */
    public static function assertValid(mixed $sections, mixed $theme): void
    {
        $schema = new self;
        $schema->check($sections, $theme);

        if ($schema->errors) {
            throw ValidationException::withMessages(array_map(fn (string $m) => [$m], $schema->errors));
        }
    }

    private function check(mixed $sections, mixed $theme): void
    {
        $max = (int) config('invitation.limits.json_bytes');

        // Size first: nothing else is worth walking when the document is absurd.
        if (strlen((string) json_encode([$sections, $theme])) > $max) {
            $this->errors['sections'] = 'El contenido de la versión supera ' . intdiv($max, 1024) . ' KB.';

            return;
        }

        $this->sections($sections);
        $this->theme($theme);
    }

    private function sections(mixed $sections): void
    {
        if (! is_array($sections) || ! array_is_list($sections)) {
            $this->errors['sections'] = 'Las secciones deben ser una lista.';

            return;
        }

        $limit = (int) config('invitation.limits.sections');
        if (count($sections) > $limit) {
            $this->errors['sections'] = "Máximo {$limit} secciones.";

            return;
        }

        $types = config('invitation.section_types');
        $seen = [];

        foreach ($sections as $i => $section) {
            $at = "sections.{$i}";

            if (! is_array($section)) {
                $this->errors[$at] = 'Cada sección debe ser un objeto.';

                continue;
            }

            $unknown = array_diff(array_keys($section), ['type', 'enabled', 'order', 'title', 'text', 'settings']);
            if ($unknown) {
                $this->errors[$at] = 'Campos no permitidos: ' . implode(', ', array_map('strval', $unknown)) . '.';
            }

            $type = $section['type'] ?? null;
            if (! is_string($type) || ! in_array($type, $types, true)) {
                $this->errors["{$at}.type"] = 'Tipo de sección no permitido.';
            } elseif (isset($seen[$type])) {
                $this->errors["{$at}.type"] = 'Este tipo de sección ya está en la lista.';
            } else {
                $seen[$type] = true;
            }

            if (! array_key_exists('enabled', $section) || ! is_bool($section['enabled'])) {
                $this->errors["{$at}.enabled"] = 'Indica si la sección está activa (verdadero o falso).';
            } elseif ($section['enabled'] === false && in_array($type, config('invitation.required_sections'), true)) {
                $this->errors["{$at}.enabled"] = 'Esta sección no se puede desactivar.';
            }

            $order = $section['order'] ?? null;
            if (! is_int($order) || $order < 0 || $order > 99) {
                $this->errors["{$at}.order"] = 'El orden debe ser un número entero entre 0 y 99.';
            }

            $this->localized($section['title'] ?? null, "{$at}.title", (int) config('invitation.limits.title'), true);
            $this->localized($section['text'] ?? null, "{$at}.text", (int) config('invitation.limits.text'), false);
            $this->settings($section['settings'] ?? null, "{$at}.settings", $type);
        }

        foreach (config('invitation.required_sections') as $required) {
            if (! isset($seen[$required])) {
                $this->errors['sections'] ??= "Falta la sección obligatoria «{$required}».";
            }
        }
    }

    private function localized(mixed $value, string $at, int $max, bool $requireEs): void
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            $this->errors[$at] = 'Debe tener texto en español e inglés (es, en).';

            return;
        }

        foreach (array_diff(array_keys($value), ['es', 'en']) as $extra) {
            $this->errors["{$at}.{$extra}"] = 'Solo se admiten los idiomas es y en.';
        }

        foreach (['es', 'en'] as $lang) {
            $text = $value[$lang] ?? null;

            if ($text === null || $text === '') {
                if ($requireEs && $lang === 'es') {
                    $this->errors["{$at}.es"] = 'El título en español es obligatorio.';
                }

                continue;
            }

            if (! is_string($text)) {
                $this->errors["{$at}.{$lang}"] = 'Debe ser texto.';
            } elseif (mb_strlen($text) > $max) {
                $this->errors["{$at}.{$lang}"] = "Máximo {$max} caracteres.";
            } elseif (preg_match('/<\/?[a-zA-Z!][^>]*>/', $text) === 1) {
                $this->errors["{$at}.{$lang}"] = 'Solo texto plano: no se permite HTML.';
            }
        }
    }

    private function settings(mixed $settings, string $at, mixed $type): void
    {
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            $this->errors[$at] = 'Los ajustes deben ser un objeto.';

            return;
        }

        $allowed = $type === 'cover' ? ['image'] : [];

        foreach (array_diff(array_keys($settings), $allowed) as $key) {
            $this->errors["{$at}.{$key}"] = 'Ajuste no permitido para esta sección.';
        }

        if (array_key_exists('image', $settings) && in_array('image', $allowed, true)) {
            $image = $settings['image'];

            if ($image !== null && (! is_string($image) || ! $this->isRelativeImage($image))) {
                $this->errors["{$at}.image"] = 'La imagen debe ser una ruta relativa que empiece por «/» (sin dominios externos).';
            }
        }
    }

    private function isRelativeImage(string $path): bool
    {
        return strlen($path) <= 255
            && preg_match('#^/(?!/)[A-Za-z0-9._~/-]*$#', $path) === 1
            && ! str_contains($path, '..');
    }

    private function theme(mixed $theme): void
    {
        if (! is_array($theme) || array_is_list($theme)) {
            $this->errors['theme'] = 'El tema debe ser un objeto.';

            return;
        }

        foreach (array_diff(array_keys($theme), ['theme', 'fonts', 'preset', 'presets']) as $key) {
            $this->errors["theme.{$key}"] = 'Campo no permitido en el tema.';
        }

        if (! is_string($theme['theme'] ?? null) || ! in_array($theme['theme'], config('invitation.themes'), true)) {
            $this->errors['theme.theme'] = 'Tema no permitido.';
        }

        $this->fonts($theme['fonts'] ?? null);
        $this->presets($theme['preset'] ?? null, $theme['presets'] ?? null);
    }

    private function fonts(mixed $fonts): void
    {
        if (! is_array($fonts) || array_is_list($fonts)) {
            $this->errors['theme.fonts'] = 'Indica las fuentes heading, body y script.';

            return;
        }

        $lists = ['heading' => 'text', 'body' => 'text', 'script' => 'script'];

        foreach (array_diff(array_keys($fonts), array_keys($lists)) as $key) {
            $this->errors["theme.fonts.{$key}"] = 'Ranura de fuente no permitida.';
        }

        foreach ($lists as $slot => $group) {
            $font = $fonts[$slot] ?? null;
            $allowed = config("invitation.fonts.{$group}");

            if (! is_string($font) || ! in_array($font, $allowed, true)) {
                $this->errors["theme.fonts.{$slot}"] = 'Fuente fuera de la lista permitida: ' . implode(', ', $allowed) . '.';
            }
        }
    }

    private function presets(mixed $preset, mixed $presets): void
    {
        $limit = (int) config('invitation.limits.presets');

        if (! is_array($presets) || $presets === [] || array_is_list($presets)) {
            $this->errors['theme.presets'] = 'Debe haber al menos un preajuste de color.';

            return;
        }

        if (count($presets) > $limit) {
            $this->errors['theme.presets'] = "Máximo {$limit} preajustes.";

            return;
        }

        if (! is_string($preset) || ! array_key_exists($preset, $presets)) {
            $this->errors['theme.preset'] = 'El preajuste elegido no existe en la lista de preajustes.';
        }

        foreach ($presets as $id => $definition) {
            $at = "theme.presets.{$id}";

            if (! is_string($id) || preg_match(self::PRESET_ID, $id) !== 1) {
                $this->errors[$at] = 'Identificador no válido (minúsculas, números, guion; máx. 32).';
            }

            if (! is_array($definition)) {
                $this->errors[$at] = 'Cada preajuste debe ser un objeto.';

                continue;
            }

            foreach (array_diff(array_keys($definition), ['label', 'colors']) as $key) {
                $this->errors["{$at}.{$key}"] = 'Campo no permitido.';
            }

            $this->localized($definition['label'] ?? null, "{$at}.label", (int) config('invitation.limits.preset_label'), true);
            $this->colors($definition['colors'] ?? null, "{$at}.colors");
        }
    }

    private function colors(mixed $colors, string $at): void
    {
        if (! is_array($colors) || array_is_list($colors)) {
            $this->errors[$at] = 'Indica los colores del preajuste.';

            return;
        }

        $keys = config('invitation.color_keys');

        foreach (array_diff(array_keys($colors), $keys) as $key) {
            $this->errors["{$at}.{$key}"] = 'Color no permitido.';
        }

        foreach ($keys as $key) {
            $value = $colors[$key] ?? null;

            if (! is_string($value) || preg_match(self::HEX, $value) !== 1) {
                $this->errors["{$at}.{$key}"] = 'Color hexadecimal no válido (por ejemplo #b04a62).';
            }
        }
    }
}
