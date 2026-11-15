<?php

namespace App\Support\TemplatePackage;

use Sabberworm\CSS\CSSList\AtRuleBlockList;
use Sabberworm\CSS\CSSList\CSSList;
use Sabberworm\CSS\CSSList\KeyFrame;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Position\Positionable;
use Sabberworm\CSS\Property\AtRuleStatement;
use Sabberworm\CSS\Property\Charset;
use Sabberworm\CSS\Property\CSSNamespace;
use Sabberworm\CSS\Property\Declaration;
use Sabberworm\CSS\Property\Import;
use Sabberworm\CSS\Renderable;
use Sabberworm\CSS\RuleSet\AtRuleSet;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Settings;
use Sabberworm\CSS\Value\CSSFunction;
use Sabberworm\CSS\Value\CSSString;
use Sabberworm\CSS\Value\Size;
use Sabberworm\CSS\Value\URL;
use Sabberworm\CSS\Value\ValueList;

/**
 * Package stylesheet sanitizer (spec 4.3). Strict parse with sabberworm/php-css-parser, then a walk of the AST that
 * validates at-rules, selectors (SelectorScoper), properties, functions and URLs, and finally a re-render from the
 * AST: the original text is never served. Any violation is an error; the output is only produced when there are none.
 */
class CssSanitizer
{
    private const AT_BLOCKS = ['media', 'supports', 'container'];

    private const PROPERTIES = [
        'color', 'background', 'background-color', 'background-image', 'background-position', 'background-position-x',
        'background-position-y', 'background-size', 'background-repeat', 'background-origin', 'background-clip',
        'background-blend-mode', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color',
        'border-style', 'border-width', 'border-radius', 'border-top-left-radius', 'border-top-right-radius',
        'border-bottom-left-radius', 'border-bottom-right-radius', 'border-top-color', 'border-right-color',
        'border-bottom-color', 'border-left-color', 'border-top-style', 'border-right-style', 'border-bottom-style',
        'border-left-style', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-image', 'border-image-source', 'border-image-slice', 'border-image-width', 'border-image-outset',
        'border-image-repeat', 'border-collapse', 'border-spacing', 'outline', 'outline-color', 'outline-style',
        'outline-width', 'outline-offset', 'box-shadow', 'text-shadow', 'opacity', 'margin', 'margin-top', 'margin-right',
        'margin-bottom', 'margin-left', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height', 'box-sizing', 'display', 'flex', 'flex-grow',
        'flex-shrink', 'flex-basis', 'flex-direction', 'flex-wrap', 'flex-flow', 'gap', 'row-gap', 'column-gap',
        'align-items', 'align-content', 'align-self', 'justify-content', 'justify-items', 'justify-self', 'place-items',
        'place-content', 'place-self', 'order', 'grid', 'grid-template', 'grid-template-columns', 'grid-template-rows',
        'grid-template-areas', 'grid-area', 'grid-column', 'grid-row', 'grid-column-start', 'grid-column-end',
        'grid-row-start', 'grid-row-end', 'grid-auto-flow', 'grid-auto-columns', 'grid-auto-rows', 'position', 'top',
        'right', 'bottom', 'left', 'inset', 'z-index', 'float', 'clear', 'overflow', 'overflow-x', 'overflow-y',
        'object-fit', 'object-position', 'aspect-ratio', 'font', 'font-family', 'font-size', 'font-weight', 'font-style',
        'font-variant', 'font-variant-caps', 'font-variant-numeric', 'font-stretch', 'font-kerning',
        'font-variation-settings', 'font-feature-settings', 'font-optical-sizing', 'letter-spacing', 'word-spacing',
        'line-height', 'text-align', 'text-align-last', 'text-transform', 'text-decoration', 'text-decoration-line',
        'text-decoration-color', 'text-decoration-style', 'text-decoration-thickness', 'text-indent', 'text-wrap',
        'text-underline-offset', 'text-overflow', 'white-space', 'word-break', 'overflow-wrap', 'hyphens',
        'vertical-align', 'list-style', 'list-style-type', 'list-style-position', 'list-style-image', 'transform',
        'transform-origin', 'transform-style', 'translate', 'rotate', 'scale', 'perspective', 'transition',
        'transition-property', 'transition-duration', 'transition-timing-function', 'transition-delay', 'animation',
        'animation-name', 'animation-duration', 'animation-timing-function', 'animation-delay',
        'animation-iteration-count', 'animation-direction', 'animation-fill-mode', 'animation-play-state', 'mask',
        'mask-image', 'mask-size', 'mask-position', 'mask-repeat', 'mask-mode', 'mask-composite', 'mask-origin',
        'mask-clip', 'clip-path', 'filter', 'backdrop-filter', 'mix-blend-mode', 'isolation', 'content', 'cursor',
        'pointer-events', 'user-select', 'container-type', 'container-name', 'visibility', 'font-smoothing',
        'line-clamp', 'box-orient', 'text-stroke', 'text-fill-color', 'text-size-adjust',
    ];

    /** Properties a rule may carry when its subject is a system hook or wraps a `<q-*>` block (spec 4.3). */
    private const SYSTEM_SAFE = [
        'color', 'background', 'background-color', 'background-image', 'background-position', 'background-size',
        'background-repeat', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color',
        'border-style', 'border-width', 'border-radius', 'border-top-left-radius', 'border-top-right-radius',
        'border-bottom-left-radius', 'border-bottom-right-radius', 'box-shadow', 'text-shadow', 'outline', 'outline-color',
        'outline-style', 'outline-width', 'outline-offset', 'font', 'font-family', 'font-size', 'font-weight',
        'font-style', 'letter-spacing', 'word-spacing', 'line-height', 'text-align', 'text-transform', 'text-decoration',
        'text-decoration-color', 'text-underline-offset', 'padding', 'padding-top', 'padding-right', 'padding-bottom',
        'padding-left', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'transition',
        'transition-property', 'transition-duration', 'transition-timing-function', 'transition-delay',
        'text-wrap', 'white-space', 'overflow-wrap', 'font-smoothing',
    ];

    /** Extra properties for tag-only / universal subjects (they may match any element, so no hiding or layout escape). */
    private const BARE_EXTRA = [
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height', 'box-sizing', 'object-fit', 'object-position',
        'aspect-ratio', 'display', 'flex', 'flex-direction', 'flex-wrap', 'gap', 'align-items', 'justify-content',
        'list-style', 'list-style-type', 'list-style-position', 'opacity', 'vertical-align', 'hyphens',
    ];

    private const FUNCTIONS = [
        'calc', 'min', 'max', 'clamp', 'var', 'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color-mix',
        'linear-gradient', 'radial-gradient', 'conic-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient',
        'cubic-bezier', 'steps', 'translate', 'translatex', 'translatey', 'translatez', 'translate3d', 'scale', 'scalex',
        'scaley', 'scale3d', 'rotate', 'rotatex', 'rotatey', 'rotatez', 'rotate3d', 'skew', 'skewx', 'skewy', 'matrix',
        'perspective', 'blur', 'brightness', 'contrast', 'drop-shadow', 'grayscale', 'hue-rotate', 'invert', 'opacity',
        'saturate', 'sepia', 'url', 'env', 'minmax', 'repeat', 'fit-content', 'circle', 'ellipse', 'inset', 'polygon',
    ];

    private const URL_PROPERTIES = [
        'background', 'background-image', 'mask', 'mask-image', 'border-image', 'border-image-source', 'list-style',
        'list-style-image',
    ];

    private const STRING_PROPERTIES = [
        'font', 'font-family', 'grid-template-areas', 'grid-template', 'grid', 'font-variation-settings', 'font-feature-settings',
        'content',
    ];

    private const DURATION_PROPERTIES = [
        'transition', 'transition-duration', 'transition-delay', 'animation', 'animation-duration', 'animation-delay',
    ];

    private const GENERIC_FAMILIES = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'inherit', 'initial'];

    private Report $report;

    private string $file;

    private string $slug;

    /** @var array<string, true> */
    private array $assets = [];

    /** @var array<string, true> */
    private array $families = [];

    /** @var array<string, string> original keyframes name => prefixed name */
    private array $keyframes = [];

    /** @var array<string, true> */
    private array $usedAssets = [];

    private int $rules = 0;

    private int $errorsBefore = 0;

    private SelectorScoper $scoper;

    /** @var list<string> */
    private array $warnedKeys = [];

    public function __construct(private readonly Limits $limits = new Limits) {}

    /** @return list<string> asset paths referenced by the last sanitized stylesheet */
    public function usedAssets(): array
    {
        return array_keys($this->usedAssets);
    }

    /**
     * @param  list<string>  $assets  paths present in the package (`assets/...`)
     * @param  list<string>  $families  font families declared in tokens.json
     * @param  array<string, string>  $classKinds  `system`/`wrapper` kinds of classes and `#ids` from the HTML AST
     */
    public function sanitize(string $css, string $slug, string $file, Report $report, array $assets = [], array $families = [], array $classKinds = []): ?string
    {
        $this->report = $report;
        $this->file = $file;
        $this->slug = $slug;
        $this->assets = array_fill_keys($assets, true);
        $this->families = array_fill_keys(array_map('strtolower', $families), true);
        $this->keyframes = [];
        $this->usedAssets = [];
        $this->rules = 0;
        $this->warnedKeys = [];
        $this->errorsBefore = $report->errorCount();
        $this->scoper = new SelectorScoper($slug, $classKinds);

        if (! $this->precheck($css)) {
            return null;
        }

        try {
            $document = (new Parser($css, Settings::create()->beStrict()))->parse();
        } catch (\Throwable $e) {
            $report->error($file, 'css_parse_error', 'CSS mal formado.', preg_match('/line no: (\d+)/', $e->getMessage(), $ln) ? (int) $ln[1] : null, $e->getMessage());

            return null;
        }

        $this->collectKeyframes($document);
        $this->walk($document, 0);

        if ($this->rules > 1500) {
            $report->error($file, 'css_size', 'Más de 1500 reglas.', null, (string) $this->rules);
        }
        if ($report->errorCount() > $this->errorsBefore) {
            return null;
        }

        $out = $document->render(OutputFormat::createCompact());

        if (strlen($out) > $this->limits->cssServedBytes) {
            $report->error($file, 'css_size', 'El CSS servido supera el máximo.', null, (string) strlen($out));

            return null;
        }
        if (! $this->postcheck($out)) {
            return null;
        }

        return $out;
    }

    /** Text-level rejections that do not depend on the parser's view of the stylesheet. */
    private function precheck(string $css): bool
    {
        if (strlen($css) > $this->limits->cssBytes) {
            $this->report->error($this->file, 'css_size', 'theme.css supera el máximo.', null, (string) strlen($css));

            return false;
        }
        if (str_starts_with($css, "\xEF\xBB\xBF")) {
            $this->report->error($this->file, 'css_parse_error', 'BOM al inicio del CSS.');

            return false;
        }
        if (! mb_check_encoding($css, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $css)) {
            $this->report->error($this->file, 'css_parse_error', 'El CSS debe ser UTF-8 sin caracteres de control.');

            return false;
        }
        if (preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}\x{FEFF}]/u', $css)) {
            $this->report->error($this->file, 'css_parse_error', 'Caracteres invisibles o bidireccionales.');

            return false;
        }
        if (($pos = strpos($css, '\\')) !== false) {
            $this->report->error($this->file, 'css_backslash', 'Las barras invertidas (escapes) están prohibidas.', $this->lineOf($css, $pos));

            return false;
        }
        if (($pos = strpos($css, '<')) !== false) {
            $this->report->error($this->file, 'css_parse_error', 'El carácter "<" está prohibido.', $this->lineOf($css, $pos));

            return false;
        }
        if (preg_match('/@\s*import/i', $css, $m, PREG_OFFSET_CAPTURE)) {
            $this->report->error($this->file, 'css_import', '@import está prohibido.', $this->lineOf($css, $m[0][1]));

            return false;
        }
        foreach (['/javascript\s*:/i', '/expression\s*\(/i', '/(?<![a-z-])behavior\s*:/i', '/-moz-binding/i', '/vbscript\s*:/i'] as $re) {
            if (preg_match($re, $css, $m, PREG_OFFSET_CAPTURE)) {
                $this->report->error($this->file, 'css_forbidden_property', 'Vector de ejecución conocido en el CSS.', $this->lineOf($css, $m[0][1]), $m[0][0]);

                return false;
            }
        }

        return true;
    }

    /** The output must satisfy the same invariants by itself, whatever the walk did. */
    private function postcheck(string $out): bool
    {
        $bad = str_contains($out, '\\') || str_contains($out, '<') || preg_match('/@import|@font-face|expression\s*\(|javascript:|-moz-binding|!\s*important/i', $out);
        if (! $bad && preg_match_all('/url\(([^)]*)\)/i', $out, $m)) {
            foreach ($m[1] as $arg) {
                if (! preg_match('/^"assets\/[a-z0-9][a-z0-9._\/-]*"$/', $arg)) {
                    $bad = true;
                }
            }
        }
        if (! $bad && preg_match_all('/(^|\})([^{}]+)\{/', $out, $m)) {
            // Each top-level selector list must start with the scope (at-rule prelude and keyframe steps excepted).
            foreach ($m[2] as $prelude) {
                if (str_starts_with(ltrim($prelude), '@')) {
                    continue;
                }
                foreach (preg_split('/,(?![^()]*\))/', $prelude) as $sel) {
                    if (preg_match('/^(\d+(\.\d+)?%|from|to)$/', trim($sel))) {
                        continue;
                    }
                    if (! str_starts_with(trim($sel), '[data-inv-theme="' . $this->slug . '"]') && ! str_contains($sel, '[data-inv-theme="' . $this->slug . '"]')) {
                        $bad = true;
                    }
                }
            }
        }
        if ($bad) {
            $this->report->error($this->file, 'css_parse_error', 'La salida no cumple los invariantes del saneador (defensa en profundidad).');

            return false;
        }

        return true;
    }

    private function collectKeyframes(CSSList $list): void
    {
        foreach ($list->getContents() as $item) {
            if ($item instanceof KeyFrame) {
                $name = $item->getAnimationName();
                if (preg_match('/^[A-Za-z][\w-]{0,40}$/', $name)) {
                    $this->keyframes[$name] = str_starts_with($name, $this->slug . '-') ? $name : $this->slug . '-' . $name;
                }
            } elseif ($item instanceof AtRuleBlockList) {
                $this->collectKeyframes($item);
            }
        }
    }

    private function walk(CSSList $list, int $depth): void
    {
        if ($depth > 3) {
            $this->report->error($this->file, 'css_forbidden_at_rule', 'At-rules anidados a más de 3 niveles.');

            return;
        }

        foreach ($list->getContents() as $item) {
            $line = $item instanceof Positionable ? $item->getLineNumber() : null;

            if ($item instanceof Charset) {
                $charset = $item->getCharset();
                if (strtolower(is_string($charset) ? $charset : $charset->getString()) !== 'utf-8') {
                    $this->report->error($this->file, 'css_forbidden_at_rule', '@charset distinto de utf-8.', $line);
                }
                $list->remove($item); // useless for a file served as UTF-8 and re-rendered

                continue;
            }
            if ($item instanceof Import) {
                $this->report->error($this->file, 'css_import', '@import está prohibido.', $line);
            } elseif ($item instanceof CSSNamespace || $item instanceof AtRuleStatement) {
                $this->report->error($this->file, 'css_forbidden_at_rule', 'At-rule no permitido.', $line, '@' . $item->atRuleName());
            } elseif ($item instanceof AtRuleSet) {
                $name = $item->atRuleName();
                $msg = $name === 'font-face'
                    ? '@font-face lo genera el servidor a partir de tokens.json.'
                    : 'At-rule no permitido.';
                $this->report->error($this->file, 'css_forbidden_at_rule', $msg, $line, '@' . $name);
            } elseif ($item instanceof KeyFrame) {
                $this->keyframe($item, $line);
            } elseif ($item instanceof AtRuleBlockList) {
                $this->atBlock($item, $depth, $line);
            } elseif ($item instanceof DeclarationBlock) {
                $this->rule($item, $line);
            } else {
                $this->report->error($this->file, 'css_forbidden_at_rule', 'Nodo CSS no reconocido.', $line, $item::class);
            }
        }
    }

    private function atBlock(AtRuleBlockList $block, int $depth, ?int $line): void
    {
        $name = strtolower($block->atRuleName());
        if (! in_array($name, self::AT_BLOCKS, true)) {
            $this->report->error($this->file, 'css_forbidden_at_rule', 'At-rule no permitido.', $line, '@' . $name);

            return;
        }
        $args = trim($block->atRuleArgs());
        $ok = match ($name) {
            'media' => $this->mediaOk($args),
            'supports' => $this->supportsOk($args),
            default => $this->containerOk($args),
        };
        if (! $ok) {
            $this->report->error($this->file, 'css_forbidden_at_rule', "Condición de @{$name} no permitida.", $line, $args);

            return;
        }
        $this->walk($block, $depth + 1);
    }

    private function mediaOk(string $args): bool
    {
        $feature = '/\(\s*(?:(?:min-|max-)?(?:width|height|aspect-ratio|resolution|device-pixel-ratio)\s*:\s*[0-9.]+(?:px|em|rem|dppx|dpi|x)?(?:\s*\/\s*[0-9.]+)?'
            . '|orientation\s*:\s*(?:portrait|landscape)|(?:any-)?hover\s*:\s*(?:hover|none)|(?:any-)?pointer\s*:\s*(?:fine|coarse|none)'
            . '|prefers-reduced-motion\s*:\s*(?:reduce|no-preference)|(?:width|height)\s*(?:<=|>=|<|>)\s*[0-9.]+(?:px|em|rem)'
            . '|[0-9.]+(?:px|em|rem)\s*(?:<=|<)\s*(?:width|height)\s*(?:<=|<)\s*[0-9.]+(?:px|em|rem))\s*\)/i';

        return $this->conditionOk($args, $feature, '/^(?:\s|\band\b|\bor\b|\bnot\b|\bonly\b|\bscreen\b|\ball\b|,)*$/i');
    }

    private function supportsOk(string $args): bool
    {
        $feature = '/\(\s*[a-z-]{2,40}\s*:\s*[a-z0-9 .%-]{1,40}\s*\)/i';

        return $this->conditionOk($args, $feature, '/^(?:\s|\band\b|\bor\b|\bnot\b)*$/i');
    }

    private function containerOk(string $args): bool
    {
        $feature = '/\(\s*(?:(?:min-|max-)?(?:width|height|inline-size|block-size)\s*:\s*[0-9.]+(?:px|em|rem|cqw|cqh)|(?:width|inline-size)\s*(?:<=|>=|<|>)\s*[0-9.]+(?:px|em|rem))\s*\)/i';
        $name = '/^\s*[a-z][\w-]{0,30}\s+/i';
        $args = (string) preg_replace($name, '', $args, 1);

        return $this->conditionOk($args, $feature, '/^(?:\s|\band\b|\bor\b|\bnot\b)*$/i');
    }

    private function conditionOk(string $args, string $feature, string $rest): bool
    {
        if ($args === '' || strlen($args) > 300) {
            return false;
        }
        $remaining = preg_replace($feature, ' ', $args, -1, $count);

        return $count > 0 && $remaining !== null && (bool) preg_match($rest, $remaining);
    }

    private function keyframe(KeyFrame $frames, ?int $line): void
    {
        $vendor = $frames->getVendorKeyFrame();
        $name = $frames->getAnimationName();
        if (! in_array($vendor, ['keyframes', '-webkit-keyframes'], true) && $vendor !== '') {
            $this->report->error($this->file, 'css_forbidden_at_rule', 'At-rule de keyframes con prefijo no permitido.', $line, $vendor);

            return;
        }
        if (! isset($this->keyframes[$name])) {
            $this->report->error($this->file, 'css_forbidden_at_rule', 'Nombre de @keyframes inválido.', $line, $name);

            return;
        }
        $frames->setAnimationName($this->keyframes[$name]);

        foreach ($frames->getContents() as $step) {
            if (! $step instanceof DeclarationBlock) {
                $this->report->error($this->file, 'css_forbidden_at_rule', 'Contenido inesperado en @keyframes.', $line);

                continue;
            }
            foreach ($step->getSelectors() as $sel) {
                if (! preg_match('/^(from|to|\d{1,3}(\.\d+)?%)$/i', trim($sel->getSelector()))) {
                    $this->report->error($this->file, 'css_selector_forbidden', 'Paso de @keyframes inválido.', $step->getLineNumber(), $sel->getSelector());
                }
            }
            foreach ($step->getDeclarations() as $declaration) {
                $this->declaration($declaration, 'free');
            }
        }
    }

    private function rule(DeclarationBlock $block, ?int $line): void
    {
        $this->rules++;
        $kind = 'free';
        foreach ($block->getSelectors() as $selector) {
            $scoped = $this->scoper->scope($selector->getSelector());
            if (isset($scoped['error'])) {
                $this->report->error($this->file, 'css_selector_forbidden', 'Selector no permitido: ' . $scoped['error'], $line, $selector->getSelector());

                continue;
            }
            $selector->setSelector($scoped['css']);
            $rank = ['free' => 0, 'bare' => 1, 'wrapper' => 2, 'system' => 3];
            if ($rank[$scoped['kind']] > $rank[$kind]) {
                $kind = $scoped['kind'];
            }
        }

        foreach ($block->getDeclarations() as $declaration) {
            $this->declaration($declaration, $kind);
        }
    }

    private function declaration(Declaration $d, string $kind): void
    {
        $line = $d->getLineNumber();
        $name = strtolower($d->getPropertyName());

        if ($d->getIsImportant()) {
            $this->report->error($this->file, 'css_important', '!important está prohibido.', $line, $name);
        }

        if (str_starts_with($name, '--')) {
            if (str_starts_with($name, '--inv-')) {
                $this->report->error($this->file, 'css_inv_var', 'Las variables --inv-* son del motor; usa --pkg-*.', $line, $name);
            } elseif (! preg_match('/^--pkg-[a-z0-9-]{1,40}$/', $name)) {
                $this->report->error($this->file, 'css_forbidden_property', 'Las variables propias deben llamarse --pkg-*.', $line, $name);
            }
            $this->value($d->getValue(), $name, $line);

            return;
        }

        $base = (string) preg_replace('/^-(webkit|moz)-/', '', $name);
        if (! in_array($base, self::PROPERTIES, true) || (str_starts_with($name, '-') && ! preg_match('/^-(webkit|moz)-/', $name))) {
            $this->report->error($this->file, 'css_forbidden_property', 'Propiedad fuera de la lista blanca.', $line, $name);

            return;
        }

        if ($kind === 'system' && ! in_array($base, self::SYSTEM_SAFE, true)) {
            $this->report->error($this->file, 'css_forbidden_property', 'Un bloque del sistema solo admite propiedades visuales.', $line, $name);

            return;
        }
        if ($kind === 'wrapper' || $kind === 'system') {
            $this->wrapperCheck($d, $base, $line, $kind);
        }
        if ($kind === 'bare' && ! in_array($base, self::SYSTEM_SAFE, true) && ! in_array($base, self::BARE_EXTRA, true)) {
            $this->report->error($this->file, 'css_forbidden_property', 'Un selector sin clase (etiqueta o *) solo admite propiedades visuales; usa una clase del paquete.', $line, $name);

            return;
        }

        if (is_string($d->getValue()) && in_array($base, ['animation', 'animation-name'], true) && isset($this->keyframes[$d->getValue()])) {
            $d->setValue($this->keyframes[$d->getValue()]);
        }
        $this->value($d->getValue(), $base, $line);
        $this->specialCases($d, $base, $kind, $line);
    }

    /** Wrappers of a system block may be styled freely, except for the ways of hiding or disabling what they contain. */
    private function wrapperCheck(Declaration $d, string $base, ?int $line, string $kind): void
    {
        $text = strtolower(trim($d->getValue() instanceof Renderable
            ? $d->getValue()->render(OutputFormat::createCompact())
            : (string) $d->getValue()));

        if (in_array($base, ['clip-path', 'mask', 'mask-image', 'mask-size', 'mask-position', 'mask-repeat', 'mask-mode', 'mask-composite', 'mask-origin', 'mask-clip', 'filter', 'backdrop-filter', 'pointer-events'], true)
            || (in_array($base, ['width', 'height', 'max-width', 'max-height', 'font-size'], true) && preg_match('/^0(px|em|rem|%|vh|vw)?$/', $text))
            || ($base === 'display' && $text === 'none')
            || ($base === 'opacity' && is_numeric($text) && (float) $text < 0.6)
            || ($base === 'transform' && preg_match('/scale\(\s*0/', $text))
            || ($base === 'font-size' && preg_match('/^(0|[1-7](\.\d+)?px)$/', $text))
            || ($base === 'color' && $kind === 'system' && in_array($text, ['transparent', 'rgba(0,0,0,0)'], true))) {
            $this->report->error($this->file, 'css_forbidden_property', 'Un bloque del sistema, o lo que lo contiene, no puede ocultarse ni deshabilitarse.', $line, "{$base}: {$text}");
        }
    }

    private function specialCases(Declaration $d, string $base, string $kind, ?int $line): void
    {
        $text = strtolower(trim($d->getValue() instanceof Renderable
            ? $d->getValue()->render(OutputFormat::createCompact())
            : (string) $d->getValue()));

        switch ($base) {
            case 'position':
                if (! in_array($text, ['relative', 'absolute', 'static'], true)) {
                    $this->report->error($this->file, 'css_forbidden_property', 'position solo admite relative, absolute o static.', $line, $text);
                }
                break;
            case 'display':
                if ($text === 'none' && $kind !== 'free') {
                    $this->report->error($this->file, 'css_forbidden_property', 'No se puede ocultar un bloque del sistema ni un selector genérico.', $line, $text);
                }
                break;
            case 'visibility':
                if ($text !== 'visible') {
                    $this->report->error($this->file, 'css_forbidden_property', 'visibility solo admite visible.', $line, $text);
                }
                break;
            case 'opacity':
                if ($kind !== 'free' && is_numeric($text) && (float) $text < 0.6) {
                    $this->report->error($this->file, 'css_forbidden_property', 'Opacidad inferior a 0.6 en un bloque del sistema.', $line, $text);
                }
                break;
            case 'z-index':
                if (! preg_match('/^-?\d{1,2}$/', $text) || abs((int) $text) > 10) {
                    $this->report->error($this->file, 'css_forbidden_property', 'z-index debe estar entre -10 y 10.', $line, $text);
                }
                break;
            case 'content':
                if (! in_array($text, ['none', 'normal', '""', "''"], true)) {
                    $this->report->error($this->file, 'css_forbidden_property', 'content solo admite none o "".', $line, $text);
                }
                break;
            case 'cursor':
                if (str_contains($text, 'url') || ! preg_match('/^[a-z-]+$/', $text)) {
                    $this->report->error($this->file, 'css_forbidden_property', 'cursor solo admite palabras clave.', $line, $text);
                }
                break;
            case 'pointer-events':
                if ($kind !== 'free') {
                    $this->report->error($this->file, 'css_forbidden_property', 'pointer-events no se puede tocar en bloques del sistema.', $line, $text);
                }
                break;
            case 'background':
            case 'background-attachment':
                if (str_contains($text, 'fixed')) {
                    $this->report->error($this->file, 'css_forbidden_property', 'background-attachment: fixed no está permitido.', $line, $text);
                }
                break;
            case 'animation':
            case 'animation-iteration-count':
                if (preg_match('/\binfinite\b/', $text) && ! in_array('infinite', $this->warnedKeys, true)) {
                    $this->warnedKeys[] = 'infinite';
                    $this->report->warning($this->file, 'css_costly_property', 'Animación infinita (el motor debe forzar prefers-reduced-motion).', $line);
                }
                break;
            case 'filter':
            case 'backdrop-filter':
            case 'mix-blend-mode':
                if (! in_array($base, $this->warnedKeys, true)) {
                    $this->warnedKeys[] = $base;
                    $this->report->warning($this->file, 'css_costly_property', "{$base} es costoso de pintar en móviles modestos.", $line);
                }
                break;
        }
    }

    private function value(mixed $value, string $property, ?int $line): void
    {
        if ($value instanceof ValueList) {
            if ($value instanceof CSSFunction && ! $this->functionOk($value, $property, $line)) {
                return;
            }
            $components = $value->getListComponents();
            foreach ($components as $i => $component) {
                $this->value($component, $property, $line);
                if (is_string($component) && in_array($property, ['animation', 'animation-name'], true) && isset($this->keyframes[$component])) {
                    $components[$i] = $this->keyframes[$component];
                    $value->setListComponents($components);
                }
            }

            return;
        }
        if ($value instanceof URL) {
            $this->url($value, $property, $line);

            return;
        }
        if ($value instanceof CSSString) {
            $this->string($value, $property, $line);

            return;
        }
        if ($value instanceof Size) {
            $this->size($value, $property, $line);

            return;
        }
    }

    private function functionOk(CSSFunction $fn, string $property, ?int $line): bool
    {
        $name = strtolower($fn->getName());
        if ($name === '') {
            return true; // parenthesised group inside calc()
        }
        if (! in_array($name, self::FUNCTIONS, true) && ! str_ends_with($name, '-gradient')) {
            $this->report->error($this->file, 'css_forbidden_property', 'Función CSS no permitida.', $line, $name . '()');

            return false;
        }
        if ($name === 'var') {
            $args = $fn->getArguments();
            $first = is_array($args) ? ($args[0] ?? '') : $args;
            if (! is_string($first) || ! preg_match('/^--(inv|pkg)-[a-z0-9-]{1,40}$/', $first)) {
                $this->report->error($this->file, 'css_forbidden_property', 'var() solo puede leer --inv-* o --pkg-*.', $line, is_string($first) ? $first : 'var()');

                return false;
            }
        }

        return true;
    }

    private function url(URL $url, string $property, ?int $line): void
    {
        $path = $url->getURL()->getString();
        if (! in_array($property, self::URL_PROPERTIES, true)) {
            $this->report->error($this->file, 'css_url_external', 'url() no está permitido en esta propiedad.', $line, "{$property}: {$path}");

            return;
        }
        if (! preg_match('/^assets\/[a-z0-9][a-z0-9._\/-]*$/', $path) || str_contains($path, '..') || str_contains($path, '//')) {
            $this->report->error($this->file, 'css_url_external', 'url() solo puede apuntar a un archivo de assets/ del paquete.', $line, $path);

            return;
        }
        if (! isset($this->assets[$path])) {
            $this->report->error($this->file, 'css_url_external', 'url() apunta a un archivo que no existe en el paquete.', $line, $path);

            return;
        }
        $this->usedAssets[$path] = true;
    }

    private function string(CSSString $string, string $property, ?int $line): void
    {
        $value = $string->getString();
        if (! in_array($property, self::STRING_PROPERTIES, true)) {
            $this->report->error($this->file, 'css_forbidden_property', 'Cadena no permitida en esta propiedad.', $line, "{$property}: {$value}");

            return;
        }
        if ($property === 'font-family' || $property === 'font') {
            $key = strtolower($value);
            if (! isset($this->families[$key]) && ! in_array($key, ['fraunces', 'figtree'], true)) {
                $this->report->error($this->file, 'font_not_self_hosted', 'La familia no está declarada en tokens.json con archivos propios.', $line, $value);
            }
        } elseif ($property === 'content' && $value !== '') {
            $this->report->error($this->file, 'css_forbidden_property', 'content con texto no está permitido.', $line, $value);
        } elseif (preg_match('/[<>{}]|url\(/i', $value)) {
            $this->report->error($this->file, 'css_forbidden_property', 'Cadena con caracteres no permitidos.', $line, $value);
        }
    }

    private function size(Size $size, string $property, ?int $line): void
    {
        if (in_array($property, self::DURATION_PROPERTIES, true) && in_array(strtolower((string) $size->getUnit()), ['s', 'ms'], true)) {
            $ms = $size->getSize() * (strtolower((string) $size->getUnit()) === 's' ? 1000 : 1);
            if ($ms > 4000) {
                $this->report->error($this->file, 'css_forbidden_property', 'Las duraciones y retardos no pueden pasar de 4 s.', $line, $size->getSize() . $size->getUnit());
            }
        }
    }

    private function lineOf(string $text, int $offset): int
    {
        return substr_count($text, "\n", 0, $offset) + 1;
    }
}
