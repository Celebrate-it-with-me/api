<?php

namespace App\Support\TemplatePackage;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * SVG sanitizer by tree reconstruction: the input is parsed (no network, no entities, no DOCTYPE) and a brand new
 * document is built copying only white-listed elements with white-listed, value-checked attributes. Nothing is
 * filtered by regex on the original text and a single violation rejects the file (it is never silently repaired).
 */
class SvgSanitizer
{
    private const NS = 'http://www.w3.org/2000/svg';

    private const MAX_DEPTH = 32;

    private const MAX_NODES = 2000;

    private const MAX_USE_EXPANSION = 20000;

    /** Elements that never exist in an asset: scripts, text, embedded raster, links, animation. */
    private const FORBIDDEN = [
        'script', 'style', 'foreignobject', 'image', 'a', 'animate', 'animatetransform', 'animatemotion', 'animatecolor',
        'set', 'iframe', 'object', 'embed', 'switch', 'text', 'tspan', 'textpath', 'font', 'feimage', 'handler',
        'listener', 'video', 'audio', 'canvas', 'cursor', 'view', 'mpath', 'discard',
    ];

    /** Elements allowed in the rebuilt tree (lowercased name => camelCase output). */
    private const ELEMENTS = [
        'svg' => 'svg', 'g' => 'g', 'defs' => 'defs', 'title' => 'title', 'desc' => 'desc', 'path' => 'path',
        'circle' => 'circle', 'ellipse' => 'ellipse', 'rect' => 'rect', 'line' => 'line', 'polyline' => 'polyline',
        'polygon' => 'polygon', 'lineargradient' => 'linearGradient', 'radialgradient' => 'radialGradient',
        'stop' => 'stop', 'clippath' => 'clipPath', 'mask' => 'mask', 'use' => 'use', 'filter' => 'filter',
        'fegaussianblur' => 'feGaussianBlur', 'fecolormatrix' => 'feColorMatrix', 'feoffset' => 'feOffset',
        'feblend' => 'feBlend', 'fecomposite' => 'feComposite', 'femerge' => 'feMerge', 'femergenode' => 'feMergeNode',
        'feflood' => 'feFlood',
        // Proposed additions to the spec: the botanical wash and paper textures are made with filters.
        'feturbulence' => 'feTurbulence', 'fedisplacementmap' => 'feDisplacementMap',
        'fecomponenttransfer' => 'feComponentTransfer', 'fefuncr' => 'feFuncR', 'fefuncg' => 'feFuncG',
        'fefuncb' => 'feFuncB', 'fefunca' => 'feFuncA',
    ];

    private const COSTLY = ['feturbulence', 'fedisplacementmap'];

    private const NUM = '-?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?';

    private const LEN = '(?:' . self::NUM . ')(?:px|em|rem|%|pt)?';

    private const COLOR = '(?:#[0-9a-fA-F]{3,8}|none|currentColor|transparent|[a-zA-Z]{3,20}|rgba?\(\s*[\d.%\s,\/]+\))';

    private const ID = '[A-Za-z][\w-]{0,63}';

    private int $nodes = 0;

    /** @var array<string, true> */
    private array $ids = [];

    private Report $report;

    private string $file = '';

    /** @var array<string, true> */
    private array $warned = [];

    /**
     * @return string|null the rebuilt SVG, or null when the report got errors
     */
    public function sanitize(string $svg, string $file, Report $report): ?string
    {
        $this->report = $report;
        $this->file = $file;
        $this->nodes = 0;
        $this->ids = [];
        $this->warned = [];
        $errorsBefore = $report->errorCount();

        if (str_starts_with($svg, "\xEF\xBB\xBF")) {
            $svg = substr($svg, 3);
        }
        if (! mb_check_encoding($svg, 'UTF-8') || str_contains($svg, "\0")) {
            $report->error($file, 'svg_invalid', 'El SVG debe ser UTF-8 sin bytes nulos.');

            return null;
        }
        if (preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $svg)) {
            $report->error($file, 'svg_invalid', 'Caracteres bidireccionales de control.');

            return null;
        }
        // Reject before the parser ever sees an internal subset: no XXE, no entity expansion, no parameter entities.
        if (preg_match('/<!\s*(DOCTYPE|ENTITY|ELEMENT|ATTLIST|NOTATION)/i', $svg)) {
            $report->error($file, 'svg_doctype', 'Los SVG no pueden declarar DOCTYPE ni entidades.');

            return null;
        }
        if (preg_match('/<\?xml-stylesheet/i', $svg)) {
            $report->error($file, 'svg_forbidden_element', 'Instrucción xml-stylesheet.');

            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $source = new DOMDocument;
        $loaded = $source->loadXML($svg, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $source->documentElement === null) {
            $report->error($file, 'svg_invalid', 'XML mal formado.');

            return null;
        }
        if ($source->doctype !== null) {
            $report->error($file, 'svg_doctype', 'DOCTYPE presente.');

            return null;
        }

        $root = $source->documentElement;
        if (strtolower($root->localName) !== 'svg' || $root->namespaceURI !== self::NS) {
            $report->error($file, 'svg_invalid', 'La raíz debe ser <svg> en el espacio de nombres SVG.', $root->getLineNo());

            return null;
        }

        $out = new DOMDocument('1.0', 'UTF-8');
        $this->collectIds($root);
        $rebuilt = $this->rebuild($root, $out, 1);
        if ($rebuilt === null || $report->errorCount() > $errorsBefore) {
            return null;
        }

        if (! $this->validateViewBox($rebuilt)) {
            return null;
        }
        $out->appendChild($rebuilt);
        $this->checkReferences($rebuilt);
        if ($report->errorCount() > $errorsBefore) {
            return null;
        }

        $xml = $out->saveXML($out->documentElement);

        return $xml === false ? null : $xml;
    }

    private function collectIds(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $id = $node->getAttribute('id');
            if ($id !== '') {
                if (isset($this->ids[$id])) {
                    $this->report->error($this->file, 'svg_invalid', 'id repetido en el SVG.', $node->getLineNo(), $id);
                }
                $this->ids[$id] = true;
            }
            foreach ($node->childNodes as $child) {
                $this->collectIds($child);
            }
        }
    }

    private function rebuild(DOMElement $element, DOMDocument $out, int $depth): ?DOMElement
    {
        $local = strtolower($element->localName);
        $line = $element->getLineNo();

        if ($depth > self::MAX_DEPTH) {
            $this->report->error($this->file, 'svg_invalid', 'Anidamiento demasiado profundo.', $line);

            return null;
        }
        if (++$this->nodes > self::MAX_NODES) {
            $this->report->error($this->file, 'svg_invalid', 'Demasiados nodos.', $line);

            return null;
        }
        if ($element->namespaceURI !== self::NS) {
            $this->report->error($this->file, 'svg_forbidden_element', 'Elemento fuera del espacio de nombres SVG.', $line, (string) $element->nodeName);

            return null;
        }
        if (in_array($local, self::FORBIDDEN, true) || ! isset(self::ELEMENTS[$local])) {
            $this->report->error($this->file, 'svg_forbidden_element', 'Elemento no permitido.', $line, $local);

            return null;
        }
        if (in_array($local, self::COSTLY, true) && ! isset($this->warned[$local])) {
            $this->warned[$local] = true;
            $this->report->warning($this->file, 'css_costly_property', 'Filtro costoso de pintar (' . $local . ').', $line);
        }

        $copy = $out->createElementNS(self::NS, self::ELEMENTS[$local]);
        $ok = true;

        foreach ($element->attributes as $attr) {
            /** @var DOMAttr $attr */
            if (! $this->copyAttribute($element, $attr, $copy, $local)) {
                $ok = false;
            }
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $built = $this->rebuild($child, $out, $depth + 1);
                if ($built === null) {
                    $ok = false;

                    continue;
                }
                $copy->appendChild($built);
            } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                if (trim($child->nodeValue ?? '') === '') {
                    continue;
                }
                if (! in_array($local, ['title', 'desc'], true)) {
                    $this->report->error($this->file, 'svg_forbidden_element', 'Texto fuera de <title> o <desc>.', $child->getLineNo());
                    $ok = false;

                    continue;
                }
                $copy->appendChild($out->createTextNode($child->nodeValue));
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                continue; // comments are dropped
            } elseif ($child->nodeType === XML_PI_NODE) {
                $this->report->error($this->file, 'svg_forbidden_element', 'Instrucción de proceso.', $child->getLineNo());
                $ok = false;
            } elseif ($child->nodeType === XML_ENTITY_REF_NODE) {
                $this->report->error($this->file, 'svg_doctype', 'Referencia a entidad.', $child->getLineNo());
                $ok = false;
            }
        }

        if ($local === 'svg' && $depth === 1) {
            $copy->setAttribute('xmlns', self::NS);
        }

        return $ok ? $copy : null;
    }

    private function copyAttribute(DOMElement $source, DOMAttr $attr, DOMElement $copy, string $local): bool
    {
        $name = $attr->name;
        $lower = strtolower($name);
        $value = $attr->value;
        $line = $source->getLineNo();

        // Namespace declarations: only the SVG default one, rebuilt by us.
        if ($lower === 'xmlns') {
            if ($value !== self::NS) {
                $this->report->error($this->file, 'svg_forbidden_attribute', 'xmlns distinto del de SVG.', $line, $value);

                return false;
            }

            return true;
        }
        if (str_starts_with($lower, 'xmlns:') || str_starts_with($lower, 'xlink:') || str_contains($lower, ':')) {
            $this->report->error($this->file, 'svg_forbidden_attribute', 'Atributo con espacio de nombres.', $line, $name);

            return false;
        }
        if (str_starts_with($lower, 'on') || in_array($lower, ['style', 'class', 'requiredextensions', 'systemlanguage', 'tabindex', 'role'], true)) {
            $this->report->error($this->file, 'svg_forbidden_attribute', 'Atributo no permitido.', $line, $name);

            return false;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $this->report->error($this->file, 'svg_forbidden_attribute', 'Carácter de control en el valor.', $line, $name);

            return false;
        }

        $rule = $this->attributeRule($name, $local);
        if ($rule === null) {
            $this->report->error($this->file, 'svg_forbidden_attribute', 'Atributo fuera de la lista blanca.', $line, $name);

            return false;
        }
        if (! $this->valueMatches($rule, $value, $local, $name)) {
            $code = $this->looksExternal($value) ? 'svg_external_ref' : 'svg_forbidden_attribute';
            $this->report->error($this->file, $code, 'Valor no permitido.', $line, "{$name}={$value}");

            return false;
        }

        $copy->setAttribute($name, $value);

        return true;
    }

    /** @return string|null rule name; null when the attribute is not white-listed for this element */
    private function attributeRule(string $name, string $local): ?string
    {
        static $global = [
            'id' => 'id', 'opacity' => 'num01', 'fill' => 'paint', 'fill-opacity' => 'num01', 'fill-rule' => 'fillrule',
            'stroke' => 'paint', 'stroke-width' => 'len', 'stroke-linecap' => 'linecap', 'stroke-linejoin' => 'linejoin',
            'stroke-opacity' => 'num01', 'stroke-miterlimit' => 'num', 'stroke-dasharray' => 'numlist', 'stroke-dashoffset' => 'len',
            'transform' => 'transform', 'clip-path' => 'urlref', 'mask' => 'urlref', 'filter' => 'urlref',
            'clip-rule' => 'fillrule', 'color' => 'paint',
        ];
        $perElement = [
            'svg' => ['viewBox' => 'viewbox', 'preserveAspectRatio' => 'par', 'width' => 'len', 'height' => 'len', 'version' => 'ver'],
            'path' => ['d' => 'pathdata', 'pathLength' => 'num'],
            'circle' => ['cx' => 'len', 'cy' => 'len', 'r' => 'len'],
            'ellipse' => ['cx' => 'len', 'cy' => 'len', 'rx' => 'len', 'ry' => 'len'],
            'rect' => ['x' => 'len', 'y' => 'len', 'width' => 'len', 'height' => 'len', 'rx' => 'len', 'ry' => 'len'],
            'line' => ['x1' => 'len', 'y1' => 'len', 'x2' => 'len', 'y2' => 'len'],
            'polyline' => ['points' => 'numlist'],
            'polygon' => ['points' => 'numlist'],
            'lineargradient' => ['x1' => 'len', 'y1' => 'len', 'x2' => 'len', 'y2' => 'len', 'gradientUnits' => 'units', 'gradientTransform' => 'transform', 'spreadMethod' => 'spread'],
            'radialgradient' => ['cx' => 'len', 'cy' => 'len', 'r' => 'len', 'fx' => 'len', 'fy' => 'len', 'gradientUnits' => 'units', 'gradientTransform' => 'transform', 'spreadMethod' => 'spread'],
            'stop' => ['offset' => 'len', 'stop-color' => 'paint', 'stop-opacity' => 'num01'],
            'clippath' => ['clipPathUnits' => 'units'],
            'mask' => ['x' => 'len', 'y' => 'len', 'width' => 'len', 'height' => 'len', 'maskUnits' => 'units', 'maskContentUnits' => 'units'],
            'use' => ['href' => 'localref', 'x' => 'len', 'y' => 'len', 'width' => 'len', 'height' => 'len'],
            'filter' => ['x' => 'len', 'y' => 'len', 'width' => 'len', 'height' => 'len', 'filterUnits' => 'units', 'primitiveUnits' => 'units', 'color-interpolation-filters' => 'ident'],
            'fegaussianblur' => ['in' => 'ident', 'stdDeviation' => 'numlist', 'result' => 'ident'],
            'fecolormatrix' => ['in' => 'ident', 'type' => 'ident', 'values' => 'numlist', 'result' => 'ident'],
            'feoffset' => ['in' => 'ident', 'dx' => 'num', 'dy' => 'num', 'result' => 'ident'],
            'feblend' => ['in' => 'ident', 'in2' => 'ident', 'mode' => 'ident', 'result' => 'ident'],
            'fecomposite' => ['in' => 'ident', 'in2' => 'ident', 'operator' => 'ident', 'k1' => 'num', 'k2' => 'num', 'k3' => 'num', 'k4' => 'num', 'result' => 'ident'],
            'femerge' => ['result' => 'ident'],
            'femergenode' => ['in' => 'ident'],
            'feflood' => ['flood-color' => 'paint', 'flood-opacity' => 'num01', 'result' => 'ident'],
            'feturbulence' => ['baseFrequency' => 'numlist', 'numOctaves' => 'octaves', 'seed' => 'num', 'type' => 'ident', 'stitchTiles' => 'ident', 'result' => 'ident'],
            'fedisplacementmap' => ['in' => 'ident', 'in2' => 'ident', 'scale' => 'displace', 'xChannelSelector' => 'ident', 'yChannelSelector' => 'ident', 'result' => 'ident'],
            'fecomponenttransfer' => ['in' => 'ident', 'result' => 'ident'],
            'fefuncr' => ['type' => 'ident', 'tableValues' => 'numlist', 'slope' => 'num', 'intercept' => 'num', 'amplitude' => 'num', 'exponent' => 'num', 'offset' => 'num'],
            'fefuncg' => ['type' => 'ident', 'tableValues' => 'numlist', 'slope' => 'num', 'intercept' => 'num', 'amplitude' => 'num', 'exponent' => 'num', 'offset' => 'num'],
            'fefuncb' => ['type' => 'ident', 'tableValues' => 'numlist', 'slope' => 'num', 'intercept' => 'num', 'amplitude' => 'num', 'exponent' => 'num', 'offset' => 'num'],
            'fefunca' => ['type' => 'ident', 'tableValues' => 'numlist', 'slope' => 'num', 'intercept' => 'num', 'amplitude' => 'num', 'exponent' => 'num', 'offset' => 'num'],
        ];

        // `href` is matched case-sensitively and only on <use>: any other element (e.g. <a>, <image>) is rejected earlier.
        return $perElement[$local][$name] ?? $global[$name] ?? null;
    }

    private function valueMatches(string $rule, string $value, string $local, string $name): bool
    {
        $num = self::NUM;
        $len = self::LEN;

        return match ($rule) {
            'id' => (bool) preg_match('/^' . self::ID . '$/', $value),
            'num' => (bool) preg_match("/^{$num}$/", $value) && $this->finiteWithin($value, self::MAX_NUMBER),
            'num01' => (bool) preg_match("/^{$num}$/", $value) && (float) $value >= 0 && (float) $value <= 1,
            'len' => (bool) preg_match("/^{$len}$/", $value) && $this->lenWithin($value, $local, $name),
            'ident' => (bool) preg_match('/^[A-Za-z][\w-]{0,40}$/', $value),
            'paint' => $this->paintOk($value),
            'urlref' => $value === 'none' || (bool) preg_match('/^url\(#(' . self::ID . ')\)$/', $value, $m) && isset($this->ids[$m[1]]),
            'localref' => (bool) preg_match('/^#(' . self::ID . ')$/', $value, $m) && isset($this->ids[$m[1]]),
            'fillrule' => in_array($value, ['nonzero', 'evenodd'], true),
            'linecap' => in_array($value, ['butt', 'round', 'square'], true),
            'linejoin' => in_array($value, ['miter', 'round', 'bevel'], true),
            'units' => in_array($value, ['userSpaceOnUse', 'objectBoundingBox'], true),
            'spread' => in_array($value, ['pad', 'reflect', 'repeat'], true),
            'viewbox' => (bool) preg_match("/^\s*{$num}[\s,]+{$num}[\s,]+{$num}[\s,]+{$num}\s*$/", $value),
            'par' => (bool) preg_match('/^(none|x(Min|Mid|Max)Y(Min|Mid|Max))(\s+(meet|slice))?$/', $value),
            'ver' => $value === '1.1' || $value === '1.0',
            'pathdata' => strlen($value) <= 20000 && (bool) preg_match('/^[MmLlHhVvCcSsQqTtAaZz0-9eE+\-.,\s]*$/', $value) && $this->numbersWithin($value, self::MAX_NUMBER),
            'numlist' => strlen($value) <= 8000 && (bool) preg_match('/^[0-9eE+\-.,\s%]*$/', $value) && $this->numbersWithin($value, $name === 'stdDeviation' ? self::MAX_BLUR : self::MAX_NUMBER),
            'transform' => (bool) preg_match('/^\s*(?:(?:matrix|translate|scale|rotate|skewX|skewY)\s*\([-0-9eE+.,\s]*\)[\s,]*)*$/', $value),
            'octaves' => ctype_digit($value) && (int) $value >= 1 && (int) $value <= 5,
            'displace' => (bool) preg_match("/^{$num}$/", $value) && abs((float) $value) <= 100,
            default => false,
        };
    }

    /** Largest absolute number any attribute may carry (user units): beyond it the file is a rendering bomb or not finite. */
    private const MAX_NUMBER = 1_000_000.0;

    /** `stdDeviation` of a Gaussian blur: the same ceiling as `blur()` in CSS. */
    private const MAX_BLUR = 40.0;

    /** Filter and mask regions, and the sizes of shapes, stay in a sane box. */
    private const MAX_REGION = 4000.0;

    private const MAX_SHAPE = 100_000.0;

    private function finiteWithin(string $number, float $max): bool
    {
        $f = (float) $number;

        return is_finite($f) && abs($f) <= $max;
    }

    private function numbersWithin(string $list, float $max): bool
    {
        if (! preg_match_all('/' . self::NUM . '/', $list, $m)) {
            return true;
        }
        foreach ($m[0] as $n) {
            if (! $this->finiteWithin($n, $max)) {
                return false;
            }
        }

        return true;
    }

    /** `len` values: finite, bounded, and tighter in filter/mask regions (percentages included). */
    private function lenWithin(string $value, string $local, string $name): bool
    {
        $region = in_array($local, ['filter', 'mask'], true) && in_array($name, ['x', 'y', 'width', 'height'], true);
        $percent = str_ends_with($value, '%');
        $max = $percent ? ($region ? 300.0 : 1000.0) : ($region ? self::MAX_REGION : self::MAX_SHAPE);

        return $this->finiteWithin((string) preg_replace('/[a-z%]+$/i', '', $value), $max);
    }

    private function paintOk(string $value): bool
    {
        if (preg_match('/^url\(#(' . self::ID . ')\)(\s+' . self::COLOR . ')?$/', $value, $m)) {
            return isset($this->ids[$m[1]]);
        }

        return (bool) preg_match('/^' . self::COLOR . '$/', $value) && ! str_contains(strtolower($value), 'url');
    }

    private function looksExternal(string $value): bool
    {
        return (bool) preg_match('/(https?:|\/\/|data:|javascript:|url\(\s*[^#\s])/i', $value);
    }

    private function validateViewBox(DOMElement $svg): bool
    {
        if (! $svg->hasAttribute('viewBox')) {
            $this->report->error($this->file, 'svg_invalid', 'Falta el atributo viewBox.', null);

            return false;
        }
        $parts = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox')));
        if ((float) $parts[2] <= 0 || (float) $parts[3] <= 0) {
            $this->report->error($this->file, 'svg_invalid', 'viewBox con ancho o alto no positivo.');

            return false;
        }
        // Fixed width/height are ignored by the engine (spec 4.2); they are dropped, not an error.
        $svg->removeAttribute('width');
        $svg->removeAttribute('height');

        return true;
    }

    /** `<use>` targets: no cycles and no exponential expansion ("billion laughs" through references). */
    private function checkReferences(DOMElement $root): void
    {
        $byId = [];
        $uses = 0;
        $walk = function (DOMElement $e) use (&$walk, &$byId, &$uses): void {
            if ($e->hasAttribute('id')) {
                $byId[$e->getAttribute('id')] = $e;
            }
            if ($e->localName === 'use') {
                $uses++;
            }
            foreach ($e->childNodes as $c) {
                if ($c instanceof DOMElement) {
                    $walk($c);
                }
            }
        };
        $walk($root);

        // An element that refers to itself or to one of its ancestors through clip-path, mask or filter is a cycle.
        $selfRef = function (DOMElement $e, array $ancestors) use (&$selfRef): bool {
            $chain = [...$ancestors, $e->getAttribute('id')];
            foreach (['clip-path', 'mask', 'filter'] as $attr) {
                if ($e->hasAttribute($attr) && preg_match('/url\(#([^)]+)\)/', $e->getAttribute($attr), $m) && in_array($m[1], $chain, true)) {
                    return true;
                }
            }
            foreach ($e->childNodes as $c) {
                if ($c instanceof DOMElement && $selfRef($c, $chain)) {
                    return true;
                }
            }

            return false;
        };
        if ($selfRef($root, [])) {
            $this->report->error($this->file, 'svg_invalid', 'Un elemento se referencia a sí mismo o a un ancestro (clip-path, mask o filter).');

            return;
        }

        if ($uses > 100) {
            $this->report->error($this->file, 'svg_invalid', 'Demasiados <use>.', null, (string) $uses);

            return;
        }

        $cost = function (DOMElement $e, array $stack) use (&$cost, $byId): int|false {
            $total = 1;
            if ($e->localName === 'use') {
                $target = ltrim($e->getAttribute('href'), '#');
                if (in_array($target, $stack, true)) {
                    return false;
                }
                if (isset($byId[$target])) {
                    $inner = $cost($byId[$target], [...$stack, $target]);
                    if ($inner === false) {
                        return false;
                    }
                    $total += $inner;
                }
            }
            foreach ($e->childNodes as $c) {
                if ($c instanceof DOMElement) {
                    $inner = $cost($c, $stack);
                    if ($inner === false) {
                        return false;
                    }
                    $total += $inner;
                    if ($total > self::MAX_USE_EXPANSION) {
                        return $total;
                    }
                }
            }

            return $total;
        };

        $result = $cost($root, []);
        if ($result === false) {
            $this->report->error($this->file, 'svg_invalid', 'Referencia circular entre <use>.');
        } elseif ($result > self::MAX_USE_EXPANSION) {
            $this->report->error($this->file, 'svg_invalid', 'La expansión de <use> supera el límite.', null, (string) $result);
        }
    }
}
