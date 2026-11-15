<?php

namespace App\Support\TemplatePackage;

/**
 * Parses a single complex selector with a closed grammar and rewrites it under `[data-inv-theme="<slug>"]`.
 * Anything outside the grammar (ids that are not `#pkg-*`, attributes outside the white-list, `:has()`, `:host`,
 * escapes, namespaces...) is a parse failure: a selector is never passed through verbatim.
 *
 * Result: ['css' => scoped selector, 'kind' => 'free'|'system'|'bare'] where `kind` tells how restricted the
 * declarations of the rule must be (see CssSanitizer): `system` = the subject is a system hook or a package element that
 * wraps a `<q-*>` block, `bare` = tag-only or universal subject (could match anything), `free` = a package class.
 */
class SelectorScoper
{
    private const TAGS = [
        'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'img', 'figure', 'figcaption', 'blockquote',
        'section', 'header', 'footer', 'nav', 'article', 'aside', 'hr', 'br', 'em', 'strong', 'b', 'i', 'u', 's', 'small', 'sub',
        'sup', 'mark', 'time', 'address', 'dl', 'dt', 'dd', 'button',
    ];

    private const PSEUDO_CLASSES = [
        'hover', 'focus', 'focus-visible', 'focus-within', 'active', 'first-child', 'last-child', 'only-child',
        'first-of-type', 'last-of-type', 'only-of-type', 'empty', 'root',
    ];

    private const PSEUDO_FUNCTIONS = ['not', 'is', 'where', 'nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type', 'lang'];

    private const PSEUDO_ELEMENTS = ['before', 'after', 'first-line', 'first-letter', 'marker', 'selection', 'placeholder'];

    private const ATTRS = ['lang', 'dir', 'role', 'alt', 'loading'];

    private const SYSTEM_CLASS = '/^inv-(rsvp|watermark|lang|music|button|q|cta|gallery|countdown)/';

    private const MAX_COMPOUNDS = 6;

    /**
     * @param  array<string, string>  $classKinds  class name (or `#id`) => `system` (the element is a `<q-*>` block) or
     *                                             `wrapper` (the element contains one), as collected from the HTML AST
     */
    public function __construct(private readonly string $slug, private readonly array $classKinds = []) {}

    /** @return array{css: string, kind: string}|array{error: string} */
    public function scope(string $selector): array
    {
        $selector = trim($selector);
        if ($selector === '' || strlen($selector) > 300) {
            return ['error' => 'selector vacío o demasiado largo'];
        }
        if (preg_match('/[^\x20-\x7E]/', $selector)) {
            return ['error' => 'caracteres no ASCII o de control'];
        }

        $pos = 0;
        $n = strlen($selector);
        $out = '';
        $count = 0;
        $last = null;
        $firstRootish = false;

        while (true) {
            $compound = $this->compound($selector, $pos, $count === 0, false);
            if (isset($compound['error'])) {
                return $compound;
            }
            $count++;
            if ($count === 1) {
                $firstRootish = $compound['rootish'];
            }
            if ($count > self::MAX_COMPOUNDS) {
                return ['error' => 'más de ' . self::MAX_COMPOUNDS . ' compuestos'];
            }
            $last = $compound;
            $out .= $compound['css'];

            // Combinator or end.
            $space = strspn($selector, " \t", $pos);
            $pos += $space;
            if ($pos >= $n) {
                break;
            }
            $ch = $selector[$pos];
            if ($ch === '>' || $ch === '+' || $ch === '~') {
                $pos++;
                $pos += strspn($selector, " \t", $pos);
                $out .= $ch;
            } elseif ($space > 0) {
                $out .= ' ';
            } else {
                return ['error' => "carácter inesperado «{$ch}»"];
            }
            if ($pos >= $n) {
                return ['error' => 'combinador sin compuesto'];
            }
        }

        if (! $firstRootish) {
            $out = '[data-inv-theme="' . $this->slug . '"] ' . $out;
        }

        return ['css' => $out, 'kind' => $last['kind']];
    }

    /**
     * @return array{css: string, kind: string, rootish: bool}|array{error: string}
     */
    private function compound(string $s, int &$pos, bool $first, bool $nested): array
    {
        $n = strlen($s);
        $css = '';
        $type = null;
        $classes = [];
        $ids = [];
        $hasQualifier = false;
        $rootish = false;
        $hasInvRoot = false;
        $hasScopeAttr = false;
        $hasSystemHook = false;
        $consumed = false;

        if (preg_match('/\G(\*|[a-zA-Z][a-zA-Z0-9-]*)/', $s, $m, 0, $pos)) {
            $type = strtolower($m[1]);
            $pos += strlen($m[1]);
            $consumed = true;
            if ($type === 'html' || $type === 'body') {
                if (! $first || $nested) {
                    return ['error' => "«{$type}» solo puede ir al inicio del selector"];
                }
                $rootish = true;
                $css .= '[data-inv-theme="' . $this->slug . '"]';
            } elseif ($type === '*') {
                $css .= '*';
            } elseif (str_starts_with($type, 'q-')) {
                return ['error' => "el selector de etiqueta «{$type}» no es parte del contrato; usa una clase del paquete"];
            } elseif (! in_array($type, self::TAGS, true)) {
                return ['error' => "etiqueta «{$type}» no permitida"];
            } else {
                $css .= $type;
            }
        }

        while ($pos < $n) {
            $ch = $s[$pos];

            if ($ch === '.') {
                if (! preg_match('/\G\.([a-zA-Z_][a-zA-Z0-9_-]{0,63})/', $s, $m, 0, $pos)) {
                    return ['error' => 'clase mal formada'];
                }
                $pos += strlen($m[0]);
                $classes[] = $m[1];
                $hasQualifier = true;
                $consumed = true;
                if ($m[1] === 'inv-root') {
                    $hasInvRoot = true;
                }
                $css .= $m[0];
            } elseif ($ch === '#') {
                if (! preg_match('/\G#(pkg-[a-z0-9-]{1,40})/', $s, $m, 0, $pos)) {
                    return ['error' => 'solo se permiten ids que empiecen por pkg-'];
                }
                $pos += strlen($m[0]);
                $ids[] = $m[1];
                $hasQualifier = true;
                $consumed = true;
                $css .= $m[0];
            } elseif ($ch === '[') {
                $attr = $this->attribute($s, $pos);
                if (is_array($attr)) {
                    return $attr;
                }
                // The scope attribute itself is accepted verbatim: it makes the sanitizer a fixed point (idempotent).
                if (preg_replace('/\s+/', '', $attr) === '[data-inv-theme="' . $this->slug . '"]') {
                    $hasScopeAttr = true;
                }
                $css .= $attr;
                // `data-inv-*` hooks sit on system elements (the section wrappers); any other attribute does not
                // identify a package element, so a subject made only of those still counts as "bare".
                if (str_starts_with(strtolower(substr($attr, 1, 9)), 'data-inv-')) {
                    $hasSystemHook = true;
                    $hasQualifier = true;
                }
                $consumed = true;
            } elseif ($ch === ':') {
                $pseudo = $this->pseudo($s, $pos, $first && ! $nested);
                if (isset($pseudo['error'])) {
                    return $pseudo;
                }
                if ($pseudo['root']) {
                    if ($type !== null && ! in_array($type, ['html', 'body'], true)) {
                        return ['error' => ':root combinado con una etiqueta'];
                    }
                    $rootish = true;
                    $css .= '[data-inv-theme="' . $this->slug . '"]';
                } else {
                    $css .= $pseudo['css'];
                }
                $consumed = true;
            } else {
                break;
            }
        }

        if (! $consumed) {
            return ['error' => 'compuesto vacío o carácter no permitido'];
        }

        if (($hasInvRoot || $hasScopeAttr) && $first && ! $nested) {
            if (! $hasScopeAttr) {
                $css .= '[data-inv-theme="' . $this->slug . '"]';
            }
            $rootish = true;
        }

        $kind = 'free';
        $rank = ['free' => 0, 'bare' => 1, 'wrapper' => 2, 'system' => 3];
        $consider = function (string $k) use (&$kind, $rank): void {
            if ($rank[$k] > $rank[$kind]) {
                $kind = $k;
            }
        };
        foreach ($classes as $c) {
            if (preg_match(self::SYSTEM_CLASS, $c)) {
                $consider('system');
            } elseif (isset($this->classKinds[$c])) {
                $consider($this->classKinds[$c]);
            }
        }
        foreach ($ids as $id) {
            if (isset($this->classKinds['#' . $id])) {
                $consider($this->classKinds['#' . $id]);
            }
        }
        if ($hasSystemHook && ! $hasScopeAttr) {
            $consider('system');
        }
        if (! $hasQualifier && ! $rootish) {
            $consider('bare');
        }

        return ['css' => $css, 'kind' => $kind, 'rootish' => $rootish];
    }

    /** @return string|array{error: string} */
    private function attribute(string $s, int &$pos): string|array
    {
        $re = '/\G\[\s*([a-zA-Z][a-zA-Z0-9-]*)\s*(?:([~|^$*]?=)\s*("[^"\\\\<>]{0,120}"|\'[^\'\\\\<>]{0,120}\'|[a-zA-Z0-9_-]{1,60})\s*(?:\s[iIsS])?)?\s*\]/';
        if (! preg_match($re, $s, $m, 0, $pos)) {
            return ['error' => 'atributo mal formado'];
        }
        $name = strtolower($m[1]);
        if (! (str_starts_with($name, 'data-') || str_starts_with($name, 'aria-') || in_array($name, self::ATTRS, true))) {
            return ['error' => "atributo «{$name}» fuera de la lista blanca"];
        }
        $pos += strlen($m[0]);

        return $m[0];
    }

    /** @return array{css: string, root: bool}|array{error: string} */
    private function pseudo(string $s, int &$pos, bool $mayBeRoot): array
    {
        if (! preg_match('/\G(::?)([a-zA-Z-]+)/', $s, $m, 0, $pos)) {
            return ['error' => 'pseudo mal formado'];
        }
        $colons = $m[1];
        $name = strtolower($m[2]);
        $pos += strlen($m[0]);

        if ($colons === '::' || in_array($name, ['before', 'after', 'first-line', 'first-letter'], true) && $colons === ':') {
            if (! in_array($name, self::PSEUDO_ELEMENTS, true)) {
                return ['error' => "pseudo-elemento «{$name}» no permitido"];
            }

            return ['css' => '::' . $name, 'root' => false];
        }

        $isFunction = $pos < strlen($s) && $s[$pos] === '(';
        if (! $isFunction) {
            if (! in_array($name, self::PSEUDO_CLASSES, true)) {
                return ['error' => "pseudo-clase «{$name}» no permitida"];
            }
            if ($name === 'root') {
                if (! $mayBeRoot) {
                    return ['error' => ':root solo puede ir al inicio'];
                }

                return ['css' => '', 'root' => true];
            }

            return ['css' => ':' . $name, 'root' => false];
        }

        if (! in_array($name, self::PSEUDO_FUNCTIONS, true)) {
            return ['error' => "pseudo-clase funcional «{$name}» no permitida (p. ej. :has)"];
        }
        $end = $this->matchingParen($s, $pos);
        if ($end === null) {
            return ['error' => 'paréntesis sin cerrar'];
        }
        $arg = substr($s, $pos + 1, $end - $pos - 1);
        $pos = $end + 1;

        if (in_array($name, ['not', 'is', 'where'], true)) {
            $parts = [];
            foreach (explode(',', $arg) as $piece) {
                $p = 0;
                $piece = trim($piece);
                $c = $this->compound($piece, $p, false, true);
                if (isset($c['error']) || $p !== strlen($piece)) {
                    return ['error' => "argumento de :{$name}() fuera del subconjunto permitido"];
                }
                $parts[] = $c['css'];
            }

            return ['css' => ":{$name}(" . implode(',', $parts) . ')', 'root' => false];
        }
        if ($name === 'lang') {
            if (! preg_match('/^\s*[a-z]{2,3}(-[a-z0-9]{2,8})*\s*$/i', $arg)) {
                return ['error' => 'argumento de :lang() inválido'];
            }

            return ['css' => ':lang(' . trim($arg) . ')', 'root' => false];
        }
        if (! preg_match('/^\s*(even|odd|[+-]?\d*n(\s*[+-]\s*\d+)?|[+-]?\d+)\s*$/i', $arg)) {
            return ['error' => "argumento de :{$name}() inválido"];
        }

        return ['css' => ":{$name}(" . preg_replace('/\s+/', '', $arg) . ')', 'root' => false];
    }

    private function matchingParen(string $s, int $open): ?int
    {
        $depth = 0;
        $n = strlen($s);
        for ($i = $open; $i < $n; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
            if ($depth > 3) {
                return null;
            }
        }

        return null;
    }
}
