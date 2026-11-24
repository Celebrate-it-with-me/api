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

    /**
     * Pseudo-classes that depend on the pointer or the focus (wave 7, N-4): a rule that uses one of them in any compound
     * may only change colors and shadows (CssSanitizer::STATE_SAFE). `:target`, `:checked` and `:visited` are not in
     * PSEUDO_CLASSES at all, so they stay refused.
     */
    private const STATE_PSEUDO = ['hover', 'focus', 'focus-visible', 'focus-within', 'active', 'target', 'checked', 'visited'];

    private const PSEUDO_FUNCTIONS = ['not', 'is', 'where', 'nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type', 'lang'];

    private const PSEUDO_ELEMENTS = ['before', 'after', 'first-line', 'first-letter', 'marker', 'selection', 'placeholder'];

    private const ATTRS = ['lang', 'dir', 'role', 'alt', 'loading'];

    /**
     * The closed list of system hooks a package stylesheet may target (visual properties only): the root, one class per
     * block (`inv-q--<block>`) and the parts of the cover photo (`inv-q-photo__*`). Any other `inv-*` class stays forbidden.
     */
    private const SYSTEM_CLASS = '/^inv-(root|q--(rsvp|rsvp-fab|gallery|music|countdown|locations|itinerary|dress-code|padrinos|date|names|cover-photo|language-switch|cta|seats|address|guest|closing|save-the-date|comments|tables|suggested-music|sections)|q-photo__[a-z0-9]+(?:-[a-z0-9]+)*)$/';

    /** The cover photo and its parts: decoration, so their size, layout and mask can be designed. */
    private const PHOTO_CLASS = '/^inv-(q--cover-photo|q-photo__[a-z0-9]+(?:-[a-z0-9]+)*)$/';

    /** The only classes a package owns. */
    private const PACKAGE_CLASS = '/^pkg-[a-z0-9_-]{1,59}$/';

    private const MAX_COMPOUNDS = 6;

    /**
     * Facts the engine publishes as attributes. `data-inv-opt-<id>` sits on the invitation root; `data-inv-name-len`,
     * `data-inv-guest-len` and `data-inv-has-photo` sit on the cover wrapper (`[data-inv-section="cover"]`). A package
     * may read them only as an ancestor condition of a rule about its own `pkg-*` elements (see `fact()`).
     * Value lists of the fixed facts; the options take theirs from the package's own `variants` and toggles.
     */
    private const FACTS = [
        'data-inv-name-len' => ['short', 'mid', 'long'],
        'data-inv-guest-len' => ['short', 'long'],
        'data-inv-has-photo' => ['true', 'false'],
    ];

    /** Option ids as the CSS can name them (a dot cannot be written without an escape, and escapes are refused). */
    private const OPTION_ID = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** Names that belong to the engine's fact namespace: if they are not exactly a known fact they are refused. */
    private const FACT_NAMESPACE = '/^data-inv-(opt-|name-|guest-|has-)/';

    /**
     * @param  array<string, string>  $classKinds  class name (or `#id`) => `system` (the element is a `<q-*>` block) or
     *                                             `wrapper` (the element contains one), as collected from the HTML AST
     * @param  array<string, list<string>>  $options  the package's own `variants` (id => closed list of values): the only
     *                                                `data-inv-opt-<id>="<value>"` a selector may mention
     */
    public function __construct(private readonly string $slug, private readonly array $classKinds = [], private readonly array $options = []) {}

    /** @return array{css: string, kind: string, kinds: list<string>, pseudo: bool, state: bool, subject: list<string>|null, subjectRoot: bool}|array{error: string} */
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
        $state = false;
        $anyFacts = false;
        $firstRootFacts = false;

        while (true) {
            // Cover facts go in the first compound, or in the second when the first one only holds root facts.
            $compound = $this->compound($selector, $pos, $count === 0, false, $count === 0 || ($count === 1 && $firstRootFacts));
            if (isset($compound['error'])) {
                return $compound;
            }
            $count++;
            if ($count === 1) {
                $firstRootish = $compound['rootish'];
                $firstRootFacts = $compound['factKind'] === 'root';
            }
            $anyFacts = $anyFacts || $compound['factKind'] !== null;
            if ($count > self::MAX_COMPOUNDS) {
                return ['error' => 'más de ' . self::MAX_COMPOUNDS . ' compuestos'];
            }
            $last = $compound;
            $state = $state || $compound['state'];
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

        // A fact is a condition on the root, never the subject: it needs a package element to style after it, and that
        // element can be neither a system block, the photo, the root nor a generic selector.
        if ($anyFacts) {
            if ($last['factKind'] !== null) {
                return ['error' => 'un atributo de hecho (data-inv-opt-*, data-inv-name-len...) solo es una condición: debe ir seguido de un elemento del paquete'];
            }
            if (! $last['pkg'] || array_intersect($last['kinds'], ['system', 'photo', 'root', 'bare']) !== []) {
                return ['error' => 'tras un atributo de hecho el sujeto de la regla debe ser un elemento del paquete (clase pkg-* o id pkg-*), nunca un bloque del sistema, la foto, la raíz ni un selector genérico'];
            }
        }

        if (! $firstRootish) {
            $out = '[data-inv-theme="' . $this->slug . '"] ' . $out;
        }

        return [
            'css' => $out, 'kind' => $last['kind'], 'kinds' => $last['kinds'], 'pseudo' => $last['pseudo'],
            'state' => $state, 'subject' => $last['subject'], 'subjectRoot' => $last['rootish'],
        ];
    }

    /**
     * @return array{css: string, kind: string, kinds: list<string>, rootish: bool, pseudo: bool, state: bool, subject: list<string>|null, factKind: 'root'|'cover'|null, pkg: bool}|array{error: string}
     */
    private function compound(string $s, int &$pos, bool $first, bool $nested, bool $coverOk = false): array
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
        $pseudoEl = false;
        $consumed = false;
        $nestedKinds = [];
        $state = false;
        $facts = [];
        $factKind = null;
        $coverSection = false;
        $otherQualifier = false;
        $plain = true; // only classes (no ids, attributes, pseudo-classes or pseudo-elements): the subject is "class-only"

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
                if (! preg_match(self::PACKAGE_CLASS, $m[1]) && ! preg_match(self::SYSTEM_CLASS, $m[1])) {
                    return ['error' => "la clase «{$m[1]}» no es del paquete (pkg-*) ni un gancho permitido del sistema"];
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
                $otherQualifier = true;
                $plain = false;
                $hasQualifier = true;
                $consumed = true;
                $css .= $m[0];
            } elseif ($ch === '[') {
                if (preg_match('/\G\[\s*((?i:data-inv-)[a-zA-Z0-9_-]*)/', $s, $nm, 0, $pos) && preg_match(self::FACT_NAMESPACE, strtolower($nm[1]))) {
                    $fact = $this->fact($s, $pos);
                    if (isset($fact['error'])) {
                        return $fact;
                    }
                    if ($nested || ($fact['kind'] === 'root' && ! $first) || ($fact['kind'] === 'cover' && ! $coverOk)) {
                        return ['error' => 'un atributo de hecho solo puede ir en el primer compuesto del selector (opciones: la raíz; name-len, guest-len y has-photo: la portada, también en el segundo si el primero solo lleva opciones), nunca dentro de :not()/:is() ni como sujeto'];
                    }
                    if ($factKind !== null && $factKind !== $fact['kind']) {
                        return ['error' => 'las opciones (raíz) y los hechos de la portada son elementos distintos: van en compuestos distintos'];
                    }
                    $factKind = $fact['kind'];
                    $facts[] = $fact['css'];
                    $css .= $fact['css'];
                    $plain = false;
                    $consumed = true;

                    continue;
                }
                $attr = $this->attribute($s, $pos);
                if (is_array($attr)) {
                    return $attr;
                }
                // The scope attribute itself is accepted verbatim: it makes the sanitizer a fixed point (idempotent).
                if (preg_replace('/\s+/', '', $attr) === '[data-inv-theme="' . $this->slug . '"]') {
                    $hasScopeAttr = true;
                } elseif (preg_replace('/\s+/', '', strtolower($attr)) === '[data-inv-section="cover"]' || preg_replace('/\s+/', '', strtolower($attr)) === "[data-inv-section='cover']") {
                    $coverSection = true;
                } else {
                    $otherQualifier = true;
                }
                $css .= $attr;
                // `data-inv-*` hooks sit on system elements (the section wrappers); any other attribute does not
                // identify a package element, so a subject made only of those still counts as "bare".
                $plain = false;
                if (str_starts_with(strtolower(substr($attr, 1, 9)), 'data-inv-') || preg_match('/^\[\s*data-q\s*(?:[~|^$*]?=|\])/i', $attr)) {
                    $hasSystemHook = true;
                    $hasQualifier = true;
                }
                $consumed = true;
            } elseif ($ch === ':') {
                $pseudo = $this->pseudo($s, $pos, $first && ! $nested);
                if (isset($pseudo['error'])) {
                    return $pseudo;
                }
                $plain = false;
                $state = $state || ($pseudo['state'] ?? false);
                if ($pseudo['root']) {
                    if ($type !== null && ! in_array($type, ['html', 'body'], true)) {
                        return ['error' => ':root combinado con una etiqueta'];
                    }
                    $rootish = true;
                    $css .= '[data-inv-theme="' . $this->slug . '"]';
                } else {
                    $otherQualifier = true;
                    $css .= $pseudo['css'];
                    array_push($nestedKinds, ...($pseudo['kinds'] ?? []));
                    $pseudoEl = $pseudoEl || str_starts_with($pseudo['css'], '::');
                }
                $consumed = true;
            } else {
                break;
            }
        }

        if (! $consumed) {
            return ['error' => 'compuesto vacío o carácter no permitido'];
        }

        if ($facts !== []) {
            if ($factKind === 'root') {
                // The compound of options is the root and nothing else: `[opt]`, `.inv-root[opt]`, `html[opt]`, `:root[opt]`...
                $foreignClass = array_filter($classes, fn (string $c) => $c !== 'inv-root');
                if ($coverSection || $foreignClass !== [] || $ids !== [] || $otherQualifier || ($type !== null && ! in_array($type, ['html', 'body'], true))) {
                    return ['error' => 'el compuesto con una opción solo puede combinarse con .inv-root, html, body o :root'];
                }
                if (! $hasInvRoot && ! $hasScopeAttr && ! $rootish) {
                    // The options live on the root, which is the element that carries the scope attribute.
                    $css = '[data-inv-theme="' . $this->slug . '"]' . $css;
                    $hasScopeAttr = true;
                }
            } elseif ($classes !== [] || $ids !== [] || $otherQualifier || $rootish || $hasInvRoot || $hasScopeAttr || $type !== null) {
                // The cover facts sit on the cover wrapper: only `[data-inv-section="cover"]` may go with them.
                return ['error' => 'el compuesto con un hecho de la portada solo puede combinarse con [data-inv-section="cover"]'];
            }
        } elseif ($coverSection) {
            $coverSection = false;
        }

        if (($hasInvRoot || $hasScopeAttr) && $first && ! $nested) {
            if (! $hasScopeAttr) {
                $css .= '[data-inv-theme="' . $this->slug . '"]';
            }
            $rootish = true;
        }

        // Every subject the compound can be: the checks of all of them apply to the rule (a `:is()` argument counts too).
        $kinds = [];
        foreach ($classes as $c) {
            if ($c !== 'inv-root' && preg_match(self::PHOTO_CLASS, $c)) {
                $kinds[] = 'photo';
            } elseif ($c !== 'inv-root' && preg_match(self::SYSTEM_CLASS, $c)) {
                $kinds[] = 'system';
            } elseif (isset($this->classKinds[$c])) {
                $kinds[] = $this->classKinds[$c];
            }
        }
        foreach ($ids as $id) {
            if (isset($this->classKinds['#' . $id])) {
                $kinds[] = $this->classKinds['#' . $id];
            }
        }
        if ($hasSystemHook && ! $hasScopeAttr) {
            $kinds[] = 'system';
        }
        if ($rootish) {
            $kinds[] = 'root';
        }
        if (! $hasQualifier && ! $rootish) {
            $kinds[] = 'bare';
        }
        array_push($kinds, ...$nestedKinds);
        $kinds = array_values(array_unique($kinds));
        if ($kinds === []) {
            $kinds = ['free'];
        }
        $rank = ['free' => 0, 'bare' => 1, 'root' => 2, 'wrapper' => 3, 'photo' => 4, 'system' => 5];
        $kind = 'free';
        foreach ($kinds as $k) {
            if ($rank[$k] > $rank[$kind]) {
                $kind = $k;
            }
        }

        $subject = $plain && ! $rootish && $type === null && $classes !== [] ? $classes : null;
        $pkg = $ids !== [] || array_filter($classes, fn (string $c) => (bool) preg_match(self::PACKAGE_CLASS, $c)) !== [];

        return ['css' => $css, 'kind' => $kind, 'kinds' => $kinds, 'rootish' => $rootish, 'pseudo' => $pseudoEl, 'state' => $state, 'subject' => $subject, 'factKind' => $factKind, 'pkg' => $pkg];
    }

    /**
     * `[data-inv-opt-<id>="<v>"]`, `[data-inv-name-len="short|mid|long"]`, `[data-inv-guest-len="short|long"]`,
     * `[data-inv-has-photo="true|false"]`. Exact match only (`=`), no flag, no presence-only form, a value from the closed
     * list: of the fact, or (options) of the package's own `variants`. Returns the normalized selector text.
     *
     * @return array{css: string, kind: 'root'|'cover'}|array{error: string}
     */
    private function fact(string $s, int &$pos): array
    {
        $re = '/\G\[\s*((?i:data-inv-)[a-zA-Z0-9_-]+)\s*=\s*(?:"([a-z0-9_-]{1,24})"|\'([a-z0-9_-]{1,24})\'|([a-z0-9_-]{1,24}))\s*\]/';
        if (! preg_match($re, $s, $m, 0, $pos)) {
            return ['error' => 'atributo de hecho mal formado: solo [nombre="valor"] con = (sin ^=, $=, *=, ~=, |=, flags ni forma sin valor)'];
        }
        $name = strtolower($m[1]);
        $value = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
        $kind = 'cover';
        if (isset(self::FACTS[$name])) {
            $allowed = self::FACTS[$name];
        } elseif (str_starts_with($name, 'data-inv-opt-')) {
            $id = substr($name, strlen('data-inv-opt-'));
            if (! preg_match(self::OPTION_ID, $id) || ! isset($this->options[$id])) {
                return ['error' => "la opción «{$id}» no está declarada en variants del manifiesto de este paquete"];
            }
            $allowed = $this->options[$id];
            $kind = 'root';
        } else {
            return ['error' => "atributo «{$name}» no es un hecho publicado por el motor"];
        }
        if (! in_array($value, $allowed, true)) {
            return ['error' => "valor «{$value}» fuera de la lista cerrada de {$name}"];
        }
        $pos += strlen($m[0]);

        return ['css' => '[' . $name . '="' . $value . '"]', 'kind' => $kind];
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

    /** @return array{css: string, root: bool, kinds?: list<string>, state?: bool}|array{error: string} */
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

            return ['css' => ':' . $name, 'root' => false, 'state' => in_array($name, self::STATE_PSEUDO, true)];
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
            $kinds = [];
            $state = false;
            foreach (explode(',', $arg) as $piece) {
                $p = 0;
                $piece = trim($piece);
                $c = $this->compound($piece, $p, false, true);
                if (isset($c['error']) || $p !== strlen($piece)) {
                    return ['error' => "argumento de :{$name}() fuera del subconjunto permitido"];
                }
                $parts[] = $c['css'];
                $state = $state || $c['state'];
                // `:not()` matches everything else, so only the positive forms tell what the rule can reach.
                if ($name !== 'not') {
                    array_push($kinds, ...array_diff($c['kinds'], ['free', 'bare']));
                }
            }

            return ['css' => ":{$name}(" . implode(',', $parts) . ')', 'root' => false, 'kinds' => $kinds, 'state' => $state];
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
