<?php

namespace App\Support\TemplatePackage;

/**
 * Wave 7, rule 7 (N-7): text that cannot be read by what the stylesheet itself declares. Static and conservative: it
 * crosses the colors / backgrounds / opacities the CSS gives to class-only subjects (CssSanitizer::declaredStyles) with
 * the element tree of the HTML (HtmlAstBuilder nodes), walking up the ancestors the way inheritance and paint do.
 *
 * - Contrast: the declared text color against the nearest declared background, when both are literal colors or known
 *   tokens (`--inv-*` read from tokens.json, for the active colors and for every preset). Ratio under 1.5 is refused.
 *   The runtime guard does not measure this; it is only what the author wrote.
 * - Accumulated opacity: the product of the declared opacities along the chain of ancestors of an element with text,
 *   under 0.4 is refused.
 *
 * What it does not see (and reports nothing about): values from `calc()` / unknown variables, gradients and images as
 * backgrounds, colors with alpha, rules with state pseudo-classes or pseudo-elements, and the layout/section nesting
 * (each template file is its own tree).
 */
class DeclaredStyleCheck
{
    public const MIN_RATIO = 1.5;

    public const MIN_OPACITY = 0.4;

    private const NAMED = [
        'white' => '#ffffff', 'black' => '#000000', 'gray' => '#808080', 'grey' => '#808080', 'silver' => '#c0c0c0',
        'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff', 'yellow' => '#ffff00', 'ivory' => '#fffff0',
        'beige' => '#f5f5dc', 'navy' => '#000080', 'maroon' => '#800000', 'gold' => '#ffd700', 'orange' => '#ffa500',
    ];

    /** Defaults of the engine: the page paints `--inv-surface` and writes in `--inv-ink`. */
    private const DEFAULT_COLOR = 'var(--inv-ink)';

    private const DEFAULT_BACKGROUND = 'var(--inv-surface)';

    /** @var array<string, list<string>> */
    private array $customProps = [];

    /** @var array<string, string> */
    private array $tokens = [];

    /**
     * @param  list<array{classes: list<string>, parent: int|null, text: bool}>  $nodes
     * @param  array{styles: list<array{classes: list<string>, prop: string, value: string}>, customProps: array<string, list<string>>}  $declared
     * @param  list<array<string, string>>  $tokenSets  one map per color set (`--inv-ink` => `#2b2430`...); `[]` when unknown
     */
    public function check(array $nodes, array $declared, array $tokenSets, Report $report): void
    {
        if ($nodes === [] || $declared['styles'] === []) {
            return;
        }
        $this->customProps = $declared['customProps'];
        $byClass = [];
        foreach ($declared['styles'] as $i => $style) {
            $byClass[$style['classes'][0]][] = $i;
        }
        $sets = $tokenSets === [] ? [[]] : $tokenSets;
        $reported = [];

        // Candidates of a node: every declared value whose class-only subject is contained in the node's classes.
        $candidates = [];
        foreach ($nodes as $i => $node) {
            foreach ($node['classes'] as $c) {
                foreach ($byClass[$c] ?? [] as $idx) {
                    $style = $declared['styles'][$idx];
                    if (array_diff($style['classes'], $node['classes']) === []) {
                        $candidates[$i][$style['prop']][$style['value']] = true;
                    }
                }
            }
        }

        foreach ($declared['styles'] as $style) {
            if ($style['classes'] === ['inv-root']) {
                $candidates['root'][$style['prop']][$style['value']] = true;
            }
        }

        foreach ($nodes as $i => $node) {
            if (! $node['text']) {
                continue;
            }
            $label = implode('.', $node['classes']) ?: '(sin clase)';

            // Accumulated opacity along the chain (the lowest declared value of each element).
            $product = 1.0;
            for ($at = $i; $at !== null; $at = $nodes[$at]['parent']) {
                $lowest = 1.0;
                foreach (array_keys($candidates[$at]['opacity'] ?? []) as $value) {
                    $lowest = min($lowest, $this->opacity($value));
                }
                $product *= $lowest;
            }
            if ($product < self::MIN_OPACITY && ! isset($reported['o:' . $label])) {
                $reported['o:' . $label] = true;
                $report->error('theme.css', 'low_contrast_declared', sprintf('Opacidad acumulada declarada de %.2f (mínimo %.1f) en la cadena de ancestros de un elemento con texto; no encadenes opacidades.', $product, self::MIN_OPACITY), null, '.' . $label);
            }

            // Text color and the background behind it.
            $colors = $this->nearest($nodes, $candidates, $i, 'color') ?? [self::DEFAULT_COLOR => true];
            $backgrounds = $this->nearest($nodes, $candidates, $i, 'background') ?? [self::DEFAULT_BACKGROUND => true];
            foreach ($sets as $tokens) {
                $this->tokens = $tokens;
                foreach (array_keys($colors) as $fg) {
                    foreach (array_keys($backgrounds) as $bg) {
                        $a = $this->color($fg, 0);
                        $b = $this->color($bg, 0);
                        if ($a === null || $b === null) {
                            continue;
                        }
                        $ratio = $this->ratio($a, $b);
                        $key = 'c:' . $label . ':' . $fg . ':' . $bg;
                        if ($ratio < self::MIN_RATIO && ! isset($reported[$key])) {
                            $reported[$key] = true;
                            $report->error('theme.css', 'low_contrast_declared', sprintf('El texto «%s» sobre «%s» tiene un contraste declarado de %.2f:1 (mínimo %.1f:1; para leerse, 4.5:1).', $fg, $bg, $ratio, self::MIN_RATIO), null, '.' . $label);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  list<array{classes: list<string>, parent: int|null, text: bool}>  $nodes
     * @return array<string, true>|null the declared values of the closest node (self first) that declares the property
     */
    private function nearest(array $nodes, array $candidates, int $from, string $prop): ?array
    {
        for ($at = $from; $at !== null; $at = $nodes[$at]['parent']) {
            if (! empty($candidates[$at][$prop])) {
                return $candidates[$at][$prop];
            }
        }

        // The page root (`.inv-root`, `body`) is the ancestor of everything.
        return $candidates['root'][$prop] ?? null;
    }

    private function opacity(string $value): float
    {
        if (preg_match('/^(\d*\.?\d+)(%?)$/', $value, $m)) {
            return max(0.0, min(1.0, $m[2] === '%' ? (float) $m[1] / 100 : (float) $m[1]));
        }

        return 1.0; // var(), calc()...: unknown
    }

    /** @return array{0: float, 1: float, 2: float}|null relative-luminance inputs (sRGB 0..1), or null when not a plain opaque color */
    private function color(string $text, int $depth): ?array
    {
        $text = trim(strtolower($text));
        // `background: <color>` shorthand with only a color; anything else (image, gradient, position...) is unknown.
        if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*(?:,[^)]*)?\)$/', $text, $m)) {
            if (str_starts_with($m[1], '--inv-')) {
                return isset($this->tokens[$m[1]]) ? $this->color($this->tokens[$m[1]], $depth + 1) : null;
            }
            $values = $this->customProps[$m[1]] ?? [];
            if ($depth >= 3 || count($values) !== 1) {
                return null;
            }

            return $this->color($values[0], $depth + 1);
        }
        if (isset(self::NAMED[$text])) {
            $text = self::NAMED[$text];
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $text, $m)) {
            $h = $m[1];
            if (strlen($h) === 3) {
                $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            }

            return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
        }
        if (preg_match('/^rgb\(\s*([\d.]+)(%?)[\s,]+([\d.]+)(%?)[\s,]+([\d.]+)(%?)\s*\)$/', $text, $m)) {
            return [$this->channel((float) $m[1], $m[2]), $this->channel((float) $m[3], $m[4]), $this->channel((float) $m[5], $m[6])];
        }
        if (preg_match('/^hsl\(\s*([\d.]+)(?:deg)?[\s,]+([\d.]+)%[\s,]+([\d.]+)%\s*\)$/', $text, $m)) {
            return $this->hsl((float) $m[1], (float) $m[2] / 100, (float) $m[3] / 100);
        }

        return null;
    }

    private function channel(float $v, string $unit): float
    {
        return max(0.0, min(1.0, $unit === '%' ? $v / 100 : $v / 255));
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function hsl(float $h, float $s, float $l): array
    {
        $h = fmod($h, 360.0) / 360;
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q): float {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);

            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };

        return [$f($h + 1 / 3), $f($h), $f($h - 1 / 3)];
    }

    /** @param array{0: float, 1: float, 2: float} $a @param array{0: float, 1: float, 2: float} $b */
    private function ratio(array $a, array $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    private function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(fn (float $v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
