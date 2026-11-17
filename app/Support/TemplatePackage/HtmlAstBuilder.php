<?php

namespace App\Support\TemplatePackage;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Converts a package HTML template (one file per section, plus an optional layout) into a JSON AST with closed tag,
 * attribute, block, variable and behavior lists. The HTML is never kept or re-serialized: the AST is the artifact, so
 * mutation XSS has nothing to mutate. Two independent readers must agree on the document (a strict tokenizer below and
 * the spec-compliant HTML5 parser of PHP 8.4, `Dom\HTMLDocument`); any disagreement is a `html_malformed` error.
 *
 * AST nodes:
 *   {"t":"el","tag":"div","attrs":{...},"children":[...]}
 *   {"t":"text","v":"..."}
 *   {"t":"var","path":"event.name","filter":"upper"|null}
 *   {"t":"q","name":"rsvp","props":{...},"attrs":{...},"children":[]}
 *   {"t":"if","path":"event.description","children":[<el>]}   from `data-if="event.description"` on an element
 * Attribute values: string | {"tpl":[text|var...]} | {"asset":"assets/..."} | {"assets":[{"path","w"}]}
 */
class HtmlAstBuilder
{
    public const MAX_DEPTH = 24;

    public const MAX_NODES = 1500;

    private const TAGS = [
        'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'img', 'figure', 'figcaption',
        'blockquote', 'section', 'header', 'footer', 'nav', 'article', 'aside', 'hr', 'br', 'em', 'strong', 'b', 'i', 'u',
        's', 'small', 'sub', 'sup', 'mark', 'time', 'address', 'dl', 'dt', 'dd',
    ];

    private const VOID = ['img', 'br', 'hr'];

    /** Tags whose whitespace-only text children are layout noise. */
    private const CONTAINERS = ['div', 'section', 'header', 'footer', 'nav', 'article', 'aside', 'ul', 'ol', 'dl', 'figure', 'blockquote'];

    private const HAZARDS = [
        'script', 'iframe', 'object', 'embed', 'form', 'style', 'link', 'meta', 'base', 'template', 'svg', 'math', 'noscript',
        'frame', 'frameset', 'applet', 'input', 'button', 'select', 'textarea', 'option', 'video', 'audio', 'source', 'track',
        'canvas', 'dialog', 'details', 'summary', 'slot', 'portal', 'html', 'head', 'body', 'title', 'main', 'table', 'canvas',
        'marquee', 'bgsound', 'image', 'picture', 'map', 'area', 'label', 'fieldset', 'legend', 'xmp', 'plaintext', 'listing',
    ];

    private const ROLES = ['presentation', 'none', 'img', 'list', 'listitem', 'region', 'group', 'separator'];

    private Report $report;

    private string $file;

    /** @var array<string, true> */
    private array $assets = [];

    private string $kind = 'section';

    private int $nodeCount = 0;

    /** @var array<string, int> */
    private array $blockCounts = [];

    /** @var list<string> */
    private array $usedAssets = [];

    /** @var array<string, true> */
    private array $usedVars = [];

    /** @var array<string, true> */
    private array $usedBehaviors = [];

    /** @var array<string, string> class or #id => 'system'|'wrapper' */
    private array $classKinds = [];

    /** @var array<string, true> */
    private array $ids = [];

    /**
     * The element tree as the contrast check needs it (wave 7, rule 7): classes, parent and whether the element carries
     * text of its own. A `<q-*>` block is a node with its engine class (`inv-q--<name>`) and counts as text.
     *
     * @var list<array{classes: list<string>, parent: int|null, text: bool}>
     */
    private array $nodes = [];

    /** @var list<int> indexes into of the open elements */
    private array $stack = [];

    private ?string $sectionType = null;

    private int $lastHeading = 0;

    /** @var array<string, true> keys of `strings.json` (the `t.*` variables the package may use) */
    private array $stringKeys = [];

    /** The interface string keys the package declares in strings.json. @param list<string> $keys */
    public function withStrings(array $keys): self
    {
        $this->stringKeys = array_fill_keys($keys, true);

        return $this;
    }

    /**
     * @param  list<string>  $assets  asset paths present in the package
     * @return array{ast: array, blocks: array<string, int>, assets: list<string>, vars: list<string>, behaviors: list<string>, classKinds: array<string, string>, ids: list<string>, nodes: list<array{classes: list<string>, parent: int|null, text: bool}>}|null
     */
    public function build(string $html, string $file, string $kind, ?string $sectionType, array $assets, Report $report): ?array
    {
        $this->report = $report;
        $this->file = $file;
        $this->kind = $kind;
        $this->sectionType = $sectionType;
        $this->assets = array_fill_keys($assets, true);
        $this->nodeCount = 0;
        $this->blockCounts = [];
        $this->usedAssets = [];
        $this->usedVars = [];
        $this->usedBehaviors = [];
        $this->classKinds = [];
        $this->ids = [];
        $this->nodes = [];
        $this->stack = [];
        $this->lastHeading = 0;
        $before = $report->errorCount();

        if (strlen($html) > 65_536) {
            $report->error($file, 'file_too_large', 'La plantilla HTML supera 64 KB.');

            return null;
        }
        if (! mb_check_encoding($html, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html)) {
            $report->error($file, 'html_malformed', 'El HTML debe ser UTF-8 sin caracteres de control.');

            return null;
        }
        if (preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $html)) {
            $report->error($file, 'html_malformed', 'Caracteres bidireccionales de control.');

            return null;
        }
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }

        $expected = $this->lint($html);
        if ($expected === null) {
            return null;
        }

        $doc = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>t</title></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR
        );
        $body = $doc->body;
        if ($body === null) {
            $report->error($file, 'html_malformed', 'El analizador HTML5 no produjo cuerpo.');

            return null;
        }

        // Second reader: the parser's tree must be exactly the tree the strict tokenizer saw.
        $actual = [];
        $this->preorder($body, 0, $actual);
        if ($actual !== $expected) {
            $report->error($file, 'html_malformed', 'El HTML se interpreta distinto en un analizador estricto y en uno HTML5 (etiquetas sin cerrar, anidamiento inválido o secuencias ambiguas).', null, $this->firstDifference($expected, $actual));

            return null;
        }

        $nodes = $this->children($body, 0, false);
        if ($report->errorCount() > $before) {
            return null;
        }

        if ($this->kind === 'layout' && ($this->blockCounts['sections'] ?? 0) !== 1) {
            $report->error($file, 'layout_outlet', 'layout.html debe contener exactamente un <q-sections></q-sections>.');

            return null;
        }
        if ($this->sectionType === 'rsvp' && ($this->blockCounts['rsvp'] ?? 0) !== 1) {
            $report->error($file, 'section_block_required', 'sections/rsvp.html debe contener exactamente un <q-rsvp>.');

            return null;
        }

        return [
            'ast' => ['v' => 1, 'kind' => $this->kind, 'nodes' => $nodes],
            'blocks' => $this->blockCounts,
            'assets' => array_values(array_unique($this->usedAssets)),
            'vars' => array_keys($this->usedVars),
            'behaviors' => array_keys($this->usedBehaviors),
            'classKinds' => $this->classKinds,
            'ids' => array_keys($this->ids),
            'nodes' => $this->nodes,
        ];
    }

    // ------------------------------------------------------------------ strict tokenizer

    /**
     * @return list<string>|null preorder list of "depth:tag:sortedAttrNames", or null after reporting the error
     */
    private function lint(string $s): ?array
    {
        $n = strlen($s);
        $i = 0;
        $stack = [];
        $order = [];
        $count = 0;

        while ($i < $n) {
            $lt = strpos($s, '<', $i);
            if ($lt === false) {
                break;
            }
            $i = $lt;
            $line = substr_count($s, "\n", 0, $i) + 1;

            if (substr($s, $i, 4) === '<!--') {
                $end = strpos($s, '-->', $i + 4);
                if ($end === false || preg_match('/^-?>|--!>/', substr($s, $i + 4, $end - $i - 1))) {
                    $this->report->error($this->file, 'html_malformed', 'Comentario sin cerrar o con cierre ambiguo.', $line);

                    return null;
                }
                $i = $end + 3;

                continue;
            }
            if (isset($s[$i + 1]) && ($s[$i + 1] === '!' || $s[$i + 1] === '?')) {
                $this->report->error($this->file, 'html_forbidden_tag', 'DOCTYPE, CDATA e instrucciones de proceso no están permitidos.', $line);

                return null;
            }
            if (isset($s[$i + 1]) && $s[$i + 1] === '/') {
                if (! preg_match('/\G<\/([a-z][a-z0-9-]*)>/', $s, $m, 0, $i)) {
                    $this->report->error($this->file, 'html_malformed', 'Etiqueta de cierre mal formada (solo minúsculas, sin espacios ni atributos).', $line);

                    return null;
                }
                $top = array_pop($stack);
                if ($top !== $m[1]) {
                    $this->report->error($this->file, 'html_malformed', "Cierre </{$m[1]}> sin su apertura o mal anidado.", $line);

                    return null;
                }
                $i += strlen($m[0]);

                continue;
            }
            if (! isset($s[$i + 1]) || ! ctype_alpha($s[$i + 1])) {
                $this->report->error($this->file, 'html_malformed', 'Carácter "<" suelto: escríbelo como &lt;.', $line);

                return null;
            }

            if (! preg_match('/\G<([a-zA-Z][a-zA-Z0-9-]*)/', $s, $m, 0, $i)) {
                return null;
            }
            $name = $m[1];
            $i += strlen($m[0]);

            if ($name !== strtolower($name)) {
                $this->report->error($this->file, 'html_malformed', 'Las etiquetas deben ir en minúsculas.', $line, $name);

                return null;
            }
            if (! $this->tagAllowed($name)) {
                $code = str_starts_with($name, 'q-') ? 'block_unknown' : 'html_forbidden_tag';
                $this->report->error($this->file, $code, str_starts_with($name, 'q-') ? 'Bloque <q-*> desconocido.' : 'Etiqueta no permitida.', $line, $name);

                return null;
            }

            // Attributes: name, optional quoted value. Unquoted values, duplicates and uppercase names are rejected.
            $attrNames = [];
            while (true) {
                $i += strspn($s, " \t\r\n", $i);
                if ($i >= $n) {
                    $this->report->error($this->file, 'html_malformed', 'Etiqueta sin cerrar.', $line);

                    return null;
                }
                if ($s[$i] === '>' || ($s[$i] === '/' && ($s[$i + 1] ?? '') === '>')) {
                    break;
                }
                if (! preg_match('/\G([a-z][a-z0-9-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))?(?=[\s>\/])/', $s, $a, 0, $i)) {
                    $this->report->error($this->file, 'html_malformed', 'Atributo mal formado (los valores van entre comillas y los nombres en minúsculas).', $line, substr($s, $i, 30));

                    return null;
                }
                if (isset($attrNames[$a[1]])) {
                    $this->report->error($this->file, 'html_malformed', 'Atributo repetido.', $line, $a[1]);

                    return null;
                }
                $attrNames[$a[1]] = true;
                $i += strlen($a[0]);
            }

            $selfClosed = $s[$i] === '/';
            $i += $selfClosed ? 2 : 1;
            $void = in_array($name, self::VOID, true);

            if ($selfClosed && ! $void) {
                $this->report->error($this->file, 'html_malformed', 'La sintaxis "/>" no cierra elementos que no son vacíos; escribe la etiqueta de cierre.', $line, $name);

                return null;
            }

            if (++$count > self::MAX_NODES) {
                $this->report->error($this->file, 'html_too_big', 'Demasiados elementos.', $line);

                return null;
            }
            $depth = count($stack);
            if ($depth + 1 > self::MAX_DEPTH) {
                $this->report->error($this->file, 'html_too_deep', 'Anidamiento demasiado profundo (máximo ' . self::MAX_DEPTH . ').', $line);

                return null;
            }

            $names = array_keys($attrNames);
            sort($names);
            $order[] = $depth . ':' . $name . ':' . implode(',', $names);
            if (! $void) {
                $stack[] = $name;
            }
        }

        if ($stack !== []) {
            $this->report->error($this->file, 'html_malformed', 'Etiquetas sin cerrar.', null, implode(',', $stack));

            return null;
        }

        return $order;
    }

    private function tagAllowed(string $name): bool
    {
        if (str_starts_with($name, 'q-')) {
            return isset(BlockCatalog::BLOCKS[substr($name, 2)]);
        }

        return in_array($name, self::TAGS, true) && ! in_array($name, self::HAZARDS, true);
    }

    /** @param list<string> $out */
    private function preorder(Node $node, int $depth, array &$out): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof Element) {
                continue;
            }
            $names = [];
            foreach ($child->attributes as $attr) {
                $names[] = $attr->nodeName;
            }
            sort($names);
            $out[] = $depth . ':' . $child->localName . ':' . implode(',', $names);
            $this->preorder($child, $depth + 1, $out);
        }
    }

    /** @param list<string> $a @param list<string> $b */
    private function firstDifference(array $a, array $b): string
    {
        foreach ($a as $i => $x) {
            if (($b[$i] ?? null) !== $x) {
                return 'esperado ' . $x . ', analizador HTML5: ' . ($b[$i] ?? '(nada)');
            }
        }

        return 'el analizador HTML5 añadió ' . ($b[count($a)] ?? '?');
    }

    // ------------------------------------------------------------------ AST

    /** @return list<array<string, mixed>> */
    private function children(Node $parent, int $depth, bool $insideQ): array
    {
        $nodes = [];
        $tag = $parent instanceof Element ? $parent->localName : 'body';
        $kids = iterator_to_array($parent->childNodes, false);

        foreach ($kids as $idx => $child) {
            if ($child instanceof Text) {
                $text = (string) preg_replace('/[ \t\r\n\f]+/', ' ', $child->data);
                if (trim($text) === '') {
                    if (in_array($tag, self::CONTAINERS, true) || $tag === 'body' || $idx === 0 || $idx === count($kids) - 1) {
                        continue;
                    }
                    $text = ' ';
                }
                if (trim($text) !== '' && $this->stack !== []) {
                    $this->nodes[end($this->stack)]['text'] = true;
                }
                foreach ($this->text($text, $parent instanceof Element ? $parent->getLineNo() : null) ?? [] as $piece) {
                    $nodes[] = $piece;
                }
            } elseif ($child instanceof Element) {
                $built = $this->element($child, $depth + 1);
                if ($built !== null) {
                    $nodes[] = $built;
                }
            }
            // Comments are dropped.
        }

        return $nodes;
    }

    /** @return array<string, mixed>|null */
    private function element(Element $el, int $depth): ?array
    {
        $tag = $el->localName;
        $this->nodeCount++;

        if (str_starts_with($tag, 'q-')) {
            return $this->block($el, $depth);
        }

        if (preg_match('/^h([1-6])$/', $tag, $h)) {
            $level = (int) $h[1];
            if ($this->lastHeading > 0 && $level > $this->lastHeading + 1) {
                $this->report->warning($this->file, 'heading_order', "Salto de encabezado de h{$this->lastHeading} a h{$level}.", $el->getLineNo());
            }
            $this->lastHeading = $level;
        }
        $attrs = $this->attributes($el, $tag, null);
        $ifPath = null;
        if (isset($attrs['data-if'])) {
            $ifPath = $attrs['data-if'];
            unset($attrs['data-if']);
        }
        if ($tag === 'img' && ! $el->hasAttribute('alt')) {
            $this->report->error($this->file, 'img_alt_missing', 'Toda <img> necesita alt (vacío si es decorativa).', $el->getLineNo());
        }
        if ($tag === 'img' && (! $el->hasAttribute('width') || ! $el->hasAttribute('height'))) {
            $this->report->warning($this->file, 'img_size_missing', 'Sin width y height la imagen provoca saltos de maquetación.', $el->getLineNo());
        }
        if ($tag === 'a' && $this->containsBlock($el)) {
            $this->report->error($this->file, 'block_scope', 'Un <a> no puede contener bloques <q-*>: un enlace externo no puede envolver los controles del sistema.', $el->getLineNo());
        }
        $this->nodes[] = ['classes' => $this->classList($attrs), 'parent' => $this->stack === [] ? null : end($this->stack), 'text' => false];
        $this->stack[] = array_key_last($this->nodes);
        $kids = $this->children($el, $depth, false);
        array_pop($this->stack);

        $this->trackAncestry($el, $attrs);

        $node = ['t' => 'el', 'tag' => $tag, 'attrs' => $attrs ?: new \stdClass, 'children' => $kids];

        return $ifPath === null ? $node : ['t' => 'if', 'path' => $ifPath, 'children' => [$node]];
    }

    /** @return array<string, mixed>|null */
    private function block(Element $el, int $depth): ?array
    {
        $name = substr($el->localName, 2);
        $spec = BlockCatalog::BLOCKS[$name];
        $line = $el->getLineNo();

        if ($spec['scope'] === 'layout' && $this->kind !== 'layout') {
            $this->report->error($this->file, 'block_scope', "<q-{$name}> solo puede ir en layout.html.", $line);
        }
        if (str_starts_with($spec['scope'], 'section:') && $this->sectionType !== substr($spec['scope'], 8)) {
            $this->report->error($this->file, 'block_scope', "<q-{$name}> solo puede ir en sections/" . substr($spec['scope'], 8) . '.html.', $line);
        }
        $this->blockCounts[$name] = ($this->blockCounts[$name] ?? 0) + 1;
        if ($this->blockCounts[$name] > $spec['max']) {
            $this->report->error($this->file, 'block_too_many', "<q-{$name}> aparece más de {$spec['max']} vez/veces.", $line);
        }
        if ($el->childNodes->length > 0) {
            $this->report->error($this->file, 'block_children', "<q-{$name}> no admite contenido: se escribe vacío (<q-{$name}></q-{$name}>).", $line);
        }

        $props = [];
        $attrs = [];
        foreach ($el->attributes as $attr) {
            $key = $attr->nodeName;
            $val = $attr->value;
            if (isset($spec['props'][$key])) {
                $checked = $this->propValue($spec['props'][$key], $val);
                if ($checked === null) {
                    $this->report->error($this->file, 'block_prop_invalid', "Valor no permitido para {$key} en <q-{$name}>.", $line, $val);

                    continue;
                }
                $props[$key] = $checked;

                continue;
            }
            $one = $this->attributes($el, 'q-' . $name, $key);
            $attrs += $one;
        }

        $this->nodes[] = ['classes' => [...$this->classList($attrs), 'inv-q', 'inv-q--' . $name], 'parent' => $this->stack === [] ? null : end($this->stack), 'text' => true];
        $this->trackAncestry($el, $attrs, true);

        return ['t' => 'q', 'name' => $name, 'props' => $props ?: new \stdClass, 'attrs' => $attrs ?: new \stdClass, 'children' => []];
    }

    private function propValue(array $spec, string $value): string|int|bool|null
    {
        switch ($spec[0]) {
            case 'enum':
                return in_array($value, $spec[1], true) ? $value : null;
            case 'int':
                return preg_match('/^\d{1,3}$/', $value) && (int) $value >= $spec[1] && (int) $value <= $spec[2] ? (int) $value : null;
            case 'bool':
                return match ($value) {
                    '', 'true' => true,
                    'false' => false,
                    default => null,
                };
            case 'pattern':
                return preg_match($spec[1], $value) ? $value : null;
        }

        return null;
    }

    /**
     * Records classes and ids of every element that is, or contains, a system block, so the CSS sanitizer can refuse to
     * hide or disable them (spec 4.3, "controles del sistema").
     */
    private function trackAncestry(Element $el, array $attrs, bool $self = false): void
    {
        $hasQ = $self || $this->containsBlock($el);
        if (! $hasQ) {
            return;
        }
        // The cover photo is decoration (its size and mask can be designed); every other block is a system control.
        $names = $self ? [substr($el->localName, 2)] : $this->blockNames($el);
        $photoOnly = $names === ['cover-photo'];
        $kind = $self ? ($photoOnly ? 'photo' : 'system') : ($photoOnly ? 'photo' : 'wrapper');
        foreach (explode(' ', (string) ($attrs['class'] ?? '')) as $c) {
            if ($c !== '') {
                $this->classKinds[$c] = $this->stricter($this->classKinds[$c] ?? null, $kind);
            }
        }
        if (isset($attrs['id']) && is_string($attrs['id'])) {
            $this->classKinds['#' . $attrs['id']] = $this->stricter($this->classKinds['#' . $attrs['id']] ?? null, $kind);
        }
    }

    /** @return list<string> */
    private function classList(array $attrs): array
    {
        return array_values(array_filter(explode(' ', (string) ($attrs['class'] ?? '')), fn (string $c) => $c !== ''));
    }

    /** The more restrictive of two kinds (system > photo > wrapper). */
    private function stricter(?string $a, string $b): string
    {
        $rank = ['wrapper' => 0, 'photo' => 1, 'system' => 2];

        return $a !== null && $rank[$a] > $rank[$b] ? $a : $b;
    }

    /** @return list<string> distinct names of the `<q-*>` blocks below an element */
    private function blockNames(Element $el): array
    {
        $names = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof Element) {
                if (str_starts_with($c->localName, 'q-')) {
                    $names[substr($c->localName, 2)] = true;
                }
                foreach ($this->blockNames($c) as $n) {
                    $names[$n] = true;
                }
            }
        }

        return array_keys($names);
    }

    private function containsBlock(Element $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof Element && (str_starts_with($c->localName, 'q-') || $this->containsBlock($c))) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ attributes

    /**
     * @param  string|null  $only  validate just this attribute (used for non-prop attributes of a q-block)
     * @return array<string, mixed>
     */
    private function attributes(Element $el, string $tag, ?string $only): array
    {
        $out = [];
        $line = $el->getLineNo();
        $isQ = str_starts_with($tag, 'q-');

        foreach ($el->attributes as $attr) {
            $name = $attr->nodeName;
            if ($only !== null && $name !== $only) {
                continue;
            }
            $value = $attr->value;

            if (preg_match('/[\x00-\x1F\x7F]/', $value) && ! in_array($name, ['title', 'aria-label', 'alt'], true)) {
                $this->report->error($this->file, 'html_attr_invalid', 'Carácter de control en el valor.', $line, $name);

                continue;
            }
            if (strlen($value) > 2000) {
                $this->report->error($this->file, 'html_attr_invalid', 'Valor demasiado largo.', $line, $name);

                continue;
            }

            $checked = $this->attribute($tag, $isQ, $name, $value, $el, $line);
            if ($checked !== null) {
                $out[$name] = $checked;
            }
        }

        return $out;
    }

    private function attribute(string $tag, bool $isQ, string $name, string $value, Element $el, ?int $line): mixed
    {
        if (str_starts_with($name, 'on') || $name === 'style' || $name === 'srcdoc' || $name === 'name' || $name === 'is' || $name === 'slot') {
            $this->report->error($this->file, 'html_attr_forbidden', match (true) {
                $name === 'style' => 'Los estilos van en theme.css, no en línea.',
                str_starts_with($name, 'on') => 'Los manejadores de eventos no existen: el paquete no ejecuta JavaScript.',
                default => 'Atributo no permitido.',
            }, $line, $name);

            return null;
        }

        switch ($name) {
            case 'class':
                $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($tokens) > 10 || strlen($value) > 200) {
                    $this->report->error($this->file, 'html_attr_invalid', 'Demasiadas clases.', $line);

                    return null;
                }
                foreach ($tokens as $t) {
                    if (! preg_match('/^pkg-[a-z0-9_-]{1,59}$/', $t)) {
                        $this->report->error($this->file, 'html_attr_invalid', 'Las clases deben empezar por pkg- (minúsculas, números, guion y guion bajo); inv-* es del sistema.', $line, $t);

                        return null;
                    }
                }

                return implode(' ', $tokens);
            case 'id':
                if (! preg_match('/^pkg-[a-z0-9-]{1,40}$/', $value)) {
                    $this->report->error($this->file, 'html_attr_invalid', 'Los ids deben empezar por pkg- (minúsculas, números y guiones).', $line, $value);

                    return null;
                }
                if (isset($this->ids[$value])) {
                    $this->report->error($this->file, 'html_duplicate_id', 'Id repetido.', $line, $value);

                    return null;
                }
                $this->ids[$value] = true;

                return $value;
            case 'lang':
                return preg_match('/^(es|en)(-[A-Za-z]{2})?$/', $value) ? $value : $this->bad($name, $value, $line);
            case 'dir':
                return in_array($value, ['ltr', 'rtl'], true) ? $value : $this->bad($name, $value, $line);
            case 'role':
                return in_array($value, self::ROLES, true) ? $value : $this->bad($name, $value, $line);
            case 'aria-hidden':
                if ($value !== 'true' && $value !== 'false') {
                    return $this->bad($name, $value, $line);
                }
                if ($value === 'true' && ($isQ || $this->containsBlock($el))) {
                    $this->report->error($this->file, 'html_hidden_system_block', 'aria-hidden="true" no puede envolver un bloque <q-*>.', $line);

                    return null;
                }

                return $value;
            case 'title':
            case 'aria-label':
                return $this->template($value, $name, $line);
            case 'aria-labelledby':
                return preg_match('/^pkg-[a-z0-9-]{1,40}$/', $value) ? $value : $this->bad($name, $value, $line);
        }

        if ($name === 'data-if') {
            return $this->conditional($value, $isQ, $el, $line);
        }
        if (isset(BlockCatalog::BEHAVIORS[$name])) {
            return $this->behavior($name, $value, $tag, $el, $line);
        }

        if ($tag === 'a') {
            if ($name === 'href') {
                return $this->href($value, $line);
            }
            if ($name === 'target') {
                return $value === '_blank' ? $value : $this->bad($name, $value, $line);
            }
        }
        if ($tag === 'img') {
            switch ($name) {
                case 'src':
                    return $this->asset($value, $name, $line);
                case 'srcset':
                    return $this->srcset($value, $line);
                case 'sizes':
                    return preg_match('/^[a-z0-9 ().,:%vwh-]{1,200}$/i', $value) && ! str_contains(strtolower($value), 'url') ? $value : $this->bad($name, $value, $line);
                case 'alt':
                    return $this->template($value, 'alt', $line);
                case 'width':
                case 'height':
                    return preg_match('/^\d{1,4}$/', $value) && (int) $value >= 1 && (int) $value <= 4096 ? (int) $value : $this->bad($name, $value, $line);
                case 'loading':
                    return in_array($value, ['lazy', 'eager'], true) ? $value : $this->bad($name, $value, $line);
                case 'decoding':
                    return in_array($value, ['async', 'sync', 'auto'], true) ? $value : $this->bad($name, $value, $line);
                case 'fetchpriority':
                    return in_array($value, ['high', 'low', 'auto'], true) ? $value : $this->bad($name, $value, $line);
            }
        }
        if ($tag === 'time' && $name === 'datetime') {
            return preg_match('/^[0-9TZ:+.\- ]{4,40}$/', $value) ? $value : $this->bad($name, $value, $line);
        }

        $this->report->error($this->file, str_starts_with($name, 'data-') ? 'behavior_unknown' : 'html_attr_forbidden', 'Atributo no permitido en <' . $tag . '>.', $line, $name);

        return null;
    }

    private function bad(string $name, string $value, ?int $line): mixed
    {
        $this->report->error($this->file, 'html_attr_invalid', "Valor no permitido para «{$name}».", $line, $value);

        return null;
    }

    private function behavior(string $name, string $value, string $tag, Element $el, ?int $line): mixed
    {
        $spec = BlockCatalog::BEHAVIORS[$name];
        if (! in_array($value, $spec['values'], true)) {
            return $this->bad($name, $value, $line);
        }
        // Wave 7, rule 5 (N-5): motion behaviors never go on the music block or on anything that contains it, or the
        // player (position: fixed) would be re-anchored to an animated ancestor.
        if (in_array($name, ['data-reveal', 'data-parallax', 'data-carousel'], true) && ($tag === 'q-music' || in_array('music', $this->blockNames($el), true))) {
            $this->report->error($this->file, 'behavior_on_music', "{$name} no puede ir en <q-music> ni en un elemento que lo contenga: el reproductor es fijo y se movería con el contenedor.", $line);

            return null;
        }
        if (isset($spec['tags']) && ! in_array($tag, $spec['tags'], true)) {
            $this->report->error($this->file, 'behavior_scope', "{$name} solo se admite en: " . implode(', ', $spec['tags']) . '.', $line, $tag);

            return null;
        }
        if (($spec['forbidQ'] ?? false) && ($this->containsBlock($el) || str_starts_with($tag, 'q-'))) {
            $this->report->error($this->file, 'behavior_scope', "{$name} no puede envolver un bloque <q-*> (movería controles del sistema).", $line);

            return null;
        }
        if (isset($spec['requires']) && ! $el->hasAttribute($spec['requires'])) {
            $this->report->error($this->file, 'behavior_scope', "{$name} requiere {$spec['requires']}.", $line);

            return null;
        }
        if (isset($spec['group'])) {
            foreach (BlockCatalog::BEHAVIORS as $other => $o) {
                if ($other !== $name && ($o['group'] ?? null) === $spec['group'] && $el->hasAttribute($other)) {
                    $this->report->error($this->file, 'behavior_conflict', "{$name} y {$other} no pueden ir en el mismo elemento (ambos mueven con transform).", $line);

                    return null;
                }
            }
        }
        $this->usedBehaviors[$name . '=' . $value] = true;

        return $value;
    }

    /** `data-if="path"`: draws the element only when the variable is not empty. Never on or around a system block. */
    private function conditional(string $value, bool $isQ, Element $el, ?int $line): mixed
    {
        if ($isQ || $this->containsBlock($el)) {
            $this->report->error($this->file, 'behavior_scope', 'data-if no puede ocultar un bloque <q-*> ni un elemento que lo contenga.', $line);

            return null;
        }
        $path = trim($value);
        if (! preg_match('/^[a-z][a-z0-9_]*\.[a-zA-Z][a-zA-Z0-9_]*$/', $path)) {
            $this->report->error($this->file, 'var_invalid', 'data-if espera una variable (p. ej. event.description), sin filtros ni expresiones.', $line, $path);

            return null;
        }
        if ($this->variableType($path, $line) === null) {
            return null;
        }
        $this->usedVars[$path] = true;

        return $path;
    }

    private function href(string $value, ?int $line): mixed
    {
        if (preg_match('/^#(pkg-[a-z0-9-]{1,40}|cover|itinerary|locations|dress_code|padrinos|gallery|rsvp|music|closing|countdown|save_the_date|comments|tables|suggested_music|suggested-music)$/', $value)) {
            return $value;
        }
        if (UrlGuard::isHttps($value) && preg_match('/^https:\/\/[a-z0-9]([a-z0-9.-]{0,120}[a-z0-9])?(:\d{1,5})?(\/[A-Za-z0-9._~!$&()*+,;=:@%\/?#\[\]-]{0,400})?$/i', $value)) {
            return $value;
        }
        if (preg_match('/^(mailto:[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,120}|tel:\+?[0-9]{5,15})$/', $value)) {
            return $value;
        }
        $this->report->error($this->file, 'html_url_forbidden', 'href solo admite #ancla, https://, mailto: o tel: (nunca javascript:, data:, // ni rutas relativas).', $line, $value);

        return null;
    }

    private function asset(string $value, string $attr, ?int $line): mixed
    {
        if (! preg_match('/^assets\/[a-z0-9][a-z0-9._\/-]*$/', $value) || str_contains($value, '..') || str_contains($value, '//')) {
            $this->report->error($this->file, 'html_url_forbidden', "{$attr} solo admite archivos de assets/ del paquete.", $line, $value);

            return null;
        }
        if (! isset($this->assets[$value])) {
            $this->report->error($this->file, 'html_url_forbidden', "{$attr} apunta a un archivo que no existe en el paquete.", $line, $value);

            return null;
        }
        $this->usedAssets[] = $value;

        return ['asset' => $value];
    }

    private function srcset(string $value, ?int $line): mixed
    {
        $items = [];
        foreach (explode(',', $value) as $candidate) {
            if (! preg_match('/^\s*(assets\/[a-z0-9][a-z0-9._\/-]*)\s+(\d{2,4})w\s*$/', $candidate, $m)) {
                $this->report->error($this->file, 'html_url_forbidden', 'srcset solo admite "assets/ruta NNNw".', $line, $candidate);

                return null;
            }
            $asset = $this->asset($m[1], 'srcset', $line);
            if ($asset === null) {
                return null;
            }
            $items[] = ['path' => $m[1], 'w' => (int) $m[2]];
        }

        return $items === [] || count($items) > 6 ? $this->bad('srcset', $value, $line) : ['assets' => $items];
    }

    // ------------------------------------------------------------------ text and variables

    /** Attribute text with optional variables: plain string when it has none. */
    private function template(string $value, string $attr, ?int $line): mixed
    {
        $parts = $this->text($value, $line);
        if ($parts === null) {
            return null;
        }
        if (count($parts) === 1 && $parts[0]['t'] === 'text') {
            return $parts[0]['v'];
        }
        if ($parts === []) {
            return '';
        }

        return ['tpl' => $parts];
    }

    /**
     * Splits a text into text/var nodes. `{{ path|filter }}` with a closed list; any other brace pair is an error.
     *
     * @return list<array<string, mixed>>|null
     */
    private function text(string $text, ?int $line = null): ?array
    {
        $out = [];
        $offset = 0;
        $ok = true;

        while (preg_match('/\{\{(.*?)\}\}/s', $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $before = substr($text, $offset, $m[0][1] - $offset);
            if ($before !== '') {
                $out[] = ['t' => 'text', 'v' => $before];
            }
            $var = $this->variable($m[1][0], $line);
            if ($var === null) {
                $ok = false;
            } else {
                $out[] = $var;
            }
            $offset = $m[0][1] + strlen($m[0][0]);
        }
        $rest = substr($text, $offset);
        if (str_contains($rest, '{{') || str_contains($rest, '}}')) {
            $this->report->error($this->file, 'var_invalid', 'Llaves dobles sin cerrar o sueltas.', $line, mb_substr($rest, 0, 60));
            $ok = false;
        }
        if ($rest !== '') {
            $out[] = ['t' => 'text', 'v' => $rest];
        }

        return $ok ? $out : null;
    }

    /** @return array<string, mixed>|null */
    private function variable(string $expr, ?int $line): ?array
    {
        if (! preg_match('/^\s*([a-z][a-z0-9_]*\.[a-zA-Z][a-zA-Z0-9_]*)\s*(?:\|\s*([a-z][a-z0-9_]*)\s*)?$/', $expr, $m)) {
            $this->report->error($this->file, 'var_invalid', 'Variable inválida: solo {{ruta}} o {{ruta|filtro}}, sin expresiones.', $line, trim($expr));

            return null;
        }
        $path = $m[1];
        $filter = $m[2] ?? null;
        $type = $this->variableType($path, $line);
        if ($type === null) {
            return null;
        }
        if ($filter !== null && ! in_array($filter, BlockCatalog::filtersFor($type), true)) {
            $this->report->error($this->file, 'var_filter_unknown', 'Filtro no permitido para esta variable.', $line, "{$path}|{$filter}");

            return null;
        }
        $this->usedVars[$path] = true;

        return ['t' => 'var', 'path' => $path, 'filter' => $filter];
    }

    /** Type of a closed-context variable (`string`, `date`, `int`), or null after reporting the error. */
    private function variableType(string $path, ?int $line): ?string
    {
        if (str_starts_with($path, 't.')) {
            $key = substr($path, 2);
            if (! preg_match(BlockCatalog::STRING_KEY, $key) || ! isset($this->stringKeys[$key])) {
                $this->report->error($this->file, 'var_unknown', 't.* solo admite las claves declaradas en strings.json.', $line, $path);

                return null;
            }

            return 'string';
        }
        if (! isset(BlockCatalog::VARS[$path])) {
            $this->report->error($this->file, 'var_unknown', 'Variable fuera del contexto permitido.', $line, $path);

            return null;
        }
        [$type, $scope] = BlockCatalog::VARS[$path];
        if ($scope === 'section' && $this->kind === 'layout') {
            $this->report->error($this->file, 'var_unknown', 'Esta variable solo existe dentro de una sección.', $line, $path);

            return null;
        }

        return $type;
    }
}
