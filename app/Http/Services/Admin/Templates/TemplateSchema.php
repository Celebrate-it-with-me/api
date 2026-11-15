<?php

namespace App\Http\Services\Admin\Templates;

use App\Models\TemplatePackage;
use Illuminate\Validation\ValidationException;

/**
 * Strict validator for the content of a template version (docs/plans/admin-templates.md, point 7). Nothing the
 * public renderer cannot draw gets in: no HTML, no free CSS, hex colors, fonts from config/invitation.php, relative
 * images, the section catalog, cover and rsvp always on, the cover always first (D-15). Errors come per field
 * (`sections.2.title.es`). A version of an installed package may also name that package as its theme, use its font
 * families and carry the optional `wash` color.
 */
class TemplateSchema
{
    private const HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    private const PRESET_ID = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** @var array<string, string> */
    private array $errors = [];

    /** @var list<string> font families declared by the package this version belongs to */
    private array $packageFamilies = [];

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

    /**
     * Errors of a list of sections on its own (the `sections.json` of a package), keyed by field path.
     *
     * @return array<string, string>
     */
    public static function sectionErrors(mixed $sections): array
    {
        $schema = new self;
        $schema->sections($sections);

        return $schema->errors;
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

            $this->localized($section['title'] ?? null, "{$at}.title", (int) config('invitation.limits.title'), $type !== 'music'); // the floating music button has no visible title
            $this->localized($section['text'] ?? null, "{$at}.text", (int) config('invitation.limits.text'), false);
            $this->settings($section['settings'] ?? null, "{$at}.settings", $type);
        }

        foreach (config('invitation.required_sections') as $required) {
            if (! isset($seen[$required])) {
                $this->errors['sections'] ??= "Falta la sección obligatoria «{$required}».";
            }
        }

        $this->coverFirst($sections);
    }

    /** The cover always comes first: its order is strictly lower than every other section's. */
    private function coverFirst(array $sections): void
    {
        $cover = null;
        foreach ($sections as $i => $section) {
            if (is_array($section) && ($section['type'] ?? null) === 'cover' && is_int($section['order'] ?? null)) {
                $cover = [$i, $section['order']];
            }
        }
        if ($cover === null) {
            return;
        }
        foreach ($sections as $section) {
            if (is_array($section) && ($section['type'] ?? null) !== 'cover' && is_int($section['order'] ?? null) && $section['order'] <= $cover[1]) {
                $this->errors["sections.{$cover[0]}.order"] = 'La portada debe ir siempre primera: su orden tiene que ser menor que el de las demás secciones.';

                return;
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

        $allowed = match ($type) {
            'cover' => ['image', 'focus'],
            'gallery' => ['layout', 'maxPhotos', 'showCaptions'],
            'music' => ['title'],
            default => [],
        };

        foreach (array_diff(array_keys($settings), $allowed) as $key) {
            $this->errors["{$at}.{$key}"] = 'Ajuste no permitido para esta sección.';
        }

        $this->mediaSettings($settings, $at, $type);

        if (array_key_exists('focus', $settings) && in_array('focus', $allowed, true)) {
            $focus = $settings['focus'];

            if ($focus !== null && (! is_string($focus) || preg_match('/^\d{1,3}% \d{1,3}%$/', $focus) !== 1)) {
                $this->errors["{$at}.focus"] = 'El foco debe tener la forma «50% 30%».';
            }
        }

        if (array_key_exists('image', $settings) && in_array('image', $allowed, true)) {
            $image = $settings['image'];

            if ($image !== null && (! is_string($image) || ! $this->isRelativeImage($image))) {
                $this->errors["{$at}.image"] = 'La imagen debe ser una ruta relativa que empiece por «/» (sin dominios externos).';
            }
        }
    }

    /** Settings of the gallery and music sections (the public invitation reads only these, with defaults for the rest). */
    private function mediaSettings(array $settings, string $at, mixed $type): void
    {
        if ($type === 'gallery') {
            if (array_key_exists('layout', $settings) && ! in_array($settings['layout'], ['grid', 'carousel'], true)) {
                $this->errors["{$at}.layout"] = 'La disposición debe ser «grid» o «carousel».';
            }

            if (array_key_exists('maxPhotos', $settings) && (! is_int($settings['maxPhotos']) || $settings['maxPhotos'] < 1 || $settings['maxPhotos'] > 40)) {
                $this->errors["{$at}.maxPhotos"] = 'El máximo de fotos debe ser un número entero entre 1 y 40.';
            }

            if (array_key_exists('showCaptions', $settings) && ! is_bool($settings['showCaptions'])) {
                $this->errors["{$at}.showCaptions"] = 'Indica si se muestran los pies de foto (verdadero o falso).';
            }
        }

        if ($type === 'music' && array_key_exists('title', $settings) && $settings['title'] !== null) {
            $this->localized($settings['title'], "{$at}.title", 60, false);
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

        $name = $theme['theme'] ?? null;
        if (! is_string($name)) {
            $this->errors['theme.theme'] = 'Tema no permitido.';
        } elseif (! in_array($name, config('invitation.themes'), true)) {
            $families = TemplatePackage::declaredFamilies($name);
            if ($families === null) {
                $this->errors['theme.theme'] = 'Tema no permitido.';
            } else {
                $this->packageFamilies = $families;
            }
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
            $allowed = array_values(array_unique([...config("invitation.fonts.{$group}"), ...$this->packageFamilies]));

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

        // `wash` is the optional seventh color of the packages (a soft tint for decorations).
        foreach (array_diff(array_keys($colors), [...$keys, 'wash']) as $key) {
            $this->errors["{$at}.{$key}"] = 'Color no permitido.';
        }

        if (array_key_exists('wash', $colors) && (! is_string($colors['wash']) || preg_match(self::HEX, $colors['wash']) !== 1)) {
            $this->errors["{$at}.wash"] = 'Color hexadecimal no válido (por ejemplo #b04a62).';
        }

        foreach ($keys as $key) {
            $value = $colors[$key] ?? null;

            if (! is_string($value) || preg_match(self::HEX, $value) !== 1) {
                $this->errors["{$at}.{$key}"] = 'Color hexadecimal no válido (por ejemplo #b04a62).';
            }
        }
    }
}
