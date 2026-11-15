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
        // Logical properties (right-to-left safe spacing and borders).
        'margin-inline', 'margin-inline-start', 'margin-inline-end', 'margin-block', 'margin-block-start', 'margin-block-end',
        'padding-inline', 'padding-inline-start', 'padding-inline-end', 'padding-block', 'padding-block-start',
        'padding-block-end', 'border-inline', 'border-inline-start', 'border-inline-end', 'border-block',
        'border-block-start', 'border-block-end', 'border-inline-color', 'border-inline-style', 'border-inline-width',
        'border-block-color', 'border-block-style', 'border-block-width',
        'inline-size', 'block-size', 'min-inline-size', 'min-block-size', 'max-inline-size', 'max-block-size',
        'inset-inline', 'inset-block',
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
        'margin-inline', 'margin-inline-start', 'margin-inline-end', 'margin-block', 'margin-block-start', 'margin-block-end',
        'padding-inline', 'padding-inline-start', 'padding-inline-end', 'padding-block', 'padding-block-start',
        'padding-block-end', 'border-inline', 'border-inline-start', 'border-inline-end', 'border-block',
        'border-block-start', 'border-block-end', 'border-inline-color', 'border-inline-style', 'border-inline-width',
        'border-block-color', 'border-block-style', 'border-block-width',
    ];

    /**
     * What the photo hooks (`inv-q--cover-photo`, `inv-q-photo__*`) may also carry: the photo is decoration, not a control,
     * so its size, layout and mask can be designed (arch, torn edge); it still cannot be hidden or disabled.
     */
    private const PHOTO_EXTRA = [
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height', 'box-sizing', 'display', 'flex',
        'flex-direction', 'flex-wrap', 'gap', 'align-items', 'align-content', 'justify-content', 'justify-items',
        'place-items', 'aspect-ratio', 'object-fit', 'object-position', 'overflow', 'mask', 'mask-image', 'mask-size',
        'mask-position', 'mask-repeat', 'mask-mode', 'mask-composite', 'mask-origin', 'mask-clip', 'clip-path', 'filter',
        'opacity', 'inline-size', 'block-size', 'transform', 'translate', 'rotate', 'scale', 'position', 'top', 'right', 'bottom', 'left', 'inset', 'z-index',
    ];

    /** Extra properties for tag-only / universal subjects (they may match any element, so no hiding or layout escape). */
    private const BARE_EXTRA = [
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height', 'box-sizing', 'object-fit', 'object-position',
        'aspect-ratio', 'display', 'flex', 'flex-direction', 'flex-wrap', 'gap', 'align-items', 'justify-content',
        'list-style', 'list-style-type', 'list-style-position', 'opacity', 'vertical-align', 'hyphens',
        // The renderer stacks system blocks above (z-index 20, isolated), so a small z-index and relative positioning
        // on "every child of my class" cannot cover them.
        'position', 'z-index',
    ];

    /** Subjects that can reach a system block (or the whole page): the stricter checks apply to them. */
    private const RESTRICTED = ['root', 'bare', 'wrapper', 'photo', 'system'];

    /** What the page root (`.inv-root`, `body`, `html`, `:root`) may carry: color, background and typography. */
    private const ROOT_SAFE = [
        'color', 'background', 'background-color', 'background-image', 'background-position', 'background-position-x',
        'background-position-y', 'background-size', 'background-repeat', 'background-origin', 'background-clip',
        'background-blend-mode', 'font', 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant',
        'font-variant-caps', 'font-variant-numeric', 'font-stretch', 'font-kerning', 'font-variation-settings',
        'font-feature-settings', 'font-optical-sizing', 'letter-spacing', 'word-spacing', 'line-height', 'text-align',
        'text-transform', 'text-wrap', 'white-space', 'overflow-wrap', 'word-break', 'hyphens', 'font-smoothing',
        'text-size-adjust', 'text-decoration', 'text-decoration-color',
    ];

    /** A wrapper of a block may not move or transform what it contains, nor reposition itself. */
    private const WRAPPER_FORBIDDEN = [
        'transform', 'translate', 'scale', 'rotate', 'perspective', 'top', 'right', 'bottom', 'left', 'inset',
        'inset-inline', 'inset-block', 'text-indent',
    ];

    private const KEYFRAME_SAFE = [
        'color', 'background-color', 'background-position', 'border-color', 'border-top-color', 'border-right-color',
        'border-bottom-color', 'border-left-color', 'outline-color', 'box-shadow', 'text-shadow', 'text-decoration-color',
    ];

    private const VISIBLE_DISPLAY = ['block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'flow-root', 'list-item'];

    /** Properties whose value must never be zero or near zero on a subject that can reach a block. */
    private const SHRINKABLE = [
        'width', 'height', 'max-width', 'max-height', 'inline-size', 'block-size', 'max-inline-size', 'max-block-size',
        'font-size', 'line-height',
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

    private int $blendRules = 0;

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
        $this->blendRules = 0;
        $this->warnedKeys = [];
        $this->errorsBefore = $report->errorCount();
        $this->scoper = new SelectorScoper($slug, $classKinds);

        if (! $this->precheck($css)) {
            return null;
        }

        $startedAt = microtime(true);
        $memoryBefore = memory_get_usage();
        try {
            $document = (new Parser($css, Settings::create()->beStrict()))->parse();
        } catch (\Throwable $e) {
            $report->error($file, 'css_parse_error', 'CSS mal formado.', preg_match('/line no: (\d+)/', $e->getMessage(), $ln) ? (int) $ln[1] : null, $e->getMessage());

            return null;
        }

        if (microtime(true) - $startedAt > $this->limits->cssParseSeconds || memory_get_usage() - $memoryBefore > $this->limits->cssParseBytes) {
            $report->error($file, 'css_size', 'El CSS es demasiado costoso de analizar (tiempo o memoria).');

            return null;
        }
        $this->restrictedKeyframes = [];
        $this->pendingKeyframes = [];
        $this->ruleKinds = ['free'];
        $this->collectKeyframes($document);
        $this->walk($document, 0);
        $this->restrictedKeyframeCheck();

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
        // The parser and the serializer recurse once per level: bound the nesting before anything is parsed (W6-06).
        if (($pos = $this->tooDeep($css)) !== null) {
            $this->report->error($this->file, 'css_size', 'Anidamiento excesivo de paréntesis o llaves (máximo ' . self::MAX_PAREN_DEPTH . ' y ' . self::MAX_BRACE_DEPTH . ' niveles).', $this->lineOf($css, $pos));

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

    private const MAX_PAREN_DEPTH = 16;

    private const MAX_BRACE_DEPTH = 8;

    /** @return int|null the offset where the nesting goes over the limit */
    private function tooDeep(string $css): ?int
    {
        $paren = 0;
        $brace = 0;
        for ($i = 0, $n = strlen($css); $i < $n; $i += 1 + strcspn($css, '(){}', $i + 1)) {
            switch ($css[$i]) {
                case '(':
                    if (++$paren > self::MAX_PAREN_DEPTH) {
                        return $i;
                    }
                    break;
                case ')':
                    $paren = max(0, $paren - 1);
                    break;
                case '{':
                    if (++$brace > self::MAX_BRACE_DEPTH) {
                        return $i;
                    }
                    break;
                case '}':
                    $brace = max(0, $brace - 1);
                    break;
            }
        }

        return null;
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
        $this->pendingKeyframes[$name] = ['frames' => $frames, 'line' => $line];
        $this->ruleKinds = ['free'];

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
                $this->declaration($declaration, ['free']);
            }
        }
    }

    /** True while the rule being checked only targets ::before / ::after pseudo-elements. */
    private bool $pseudoRule = false;

    /** @var list<string> subjects of the rule being checked (see SelectorScoper) */
    private array $ruleKinds = ['free'];

    /** @var array<string, true> keyframes (original names) animated by a rule that can reach a block or the root */
    private array $restrictedKeyframes = [];

    /** @var array<string, array{frames: KeyFrame, line: ?int}> */
    private array $pendingKeyframes = [];

    private function rule(DeclarationBlock $block, ?int $line): void
    {
        $this->rules++;
        $kinds = [];
        $this->pseudoRule = $block->getSelectors() !== [];
        foreach ($block->getSelectors() as $selector) {
            $scoped = $this->scoper->scope($selector->getSelector());
            if (isset($scoped['error'])) {
                $this->report->error($this->file, 'css_selector_forbidden', 'Selector no permitido: ' . $scoped['error'], $line, $selector->getSelector());

                continue;
            }
            $selector->setSelector($scoped['css']);
            $this->pseudoRule = $this->pseudoRule && $scoped['pseudo'];
            $kinds = array_merge($kinds, $scoped['kinds']);
        }
        $kinds = array_values(array_unique($kinds));

        // What a wrapper (or the photo, or the root) draws with ::before / ::after is decoration: the renderer stacks the blocks above it.
        if ($this->pseudoRule) {
            $kinds = array_values(array_diff($kinds, ['wrapper', 'photo', 'root']));
        }
        if ($kinds === []) {
            $kinds = ['free'];
        }
        $this->ruleKinds = $kinds;

        $colors = [];
        foreach ($block->getDeclarations() as $declaration) {
            $this->declaration($declaration, $kinds);
            $name = strtolower($declaration->getPropertyName());
            if (in_array($name, ['color', 'background-color'], true)) {
                $colors[$name] = $this->valueText($declaration);
            }
        }
        if (isset($colors['color'], $colors['background-color']) && $colors['color'] === $colors['background-color'] && $this->restricted($kinds) !== []) {
            $this->report->error($this->file, 'css_forbidden_property', 'El color del texto no puede ser igual al del fondo en un bloque del sistema o lo que lo contiene.', $line, $colors['color']);
        }
    }

    /**
     * A keyframe animated on the root, a generic selector or anything that wraps or is a system block may only change
     * colors and shadows: it can never fade, move, scale, clip or hide what it animates (W6-02).
     */
    private function restrictedKeyframeCheck(): void
    {
        foreach (array_keys($this->restrictedKeyframes) as $name) {
            $pending = $this->pendingKeyframes[$name] ?? null;
            if ($pending === null) {
                continue;
            }
            foreach ($pending['frames']->getContents() as $step) {
                if (! $step instanceof DeclarationBlock) {
                    continue;
                }
                foreach ($step->getDeclarations() as $declaration) {
                    $prop = strtolower($declaration->getPropertyName());
                    if (! str_starts_with($prop, '--') && ! in_array(preg_replace('/^-(webkit|moz)-/', '', $prop), self::KEYFRAME_SAFE, true)) {
                        $this->report->error($this->file, 'css_forbidden_property', 'Un @keyframes aplicado a la raíz, a un selector genérico o a un bloque del sistema (o a lo que lo contiene) solo puede animar colores y sombras.', $declaration->getLineNumber(), "{$name}: {$prop}");
                    }
                }
            }
        }
    }

    /** @param list<string> $kinds @return list<string> the subjects that can reach a system block or the root */
    private function restricted(array $kinds): array
    {
        return array_values(array_intersect($kinds, self::RESTRICTED));
    }

    private function valueText(Declaration $d): string
    {
        return strtolower(trim($d->getValue() instanceof Renderable
            ? $d->getValue()->render(OutputFormat::createCompact())
            : (string) $d->getValue()));
    }

    /** @param list<string> $kinds */
    private function declaration(Declaration $d, array $kinds): void
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

        if ((in_array('system', $kinds, true) && ! in_array($base, self::SYSTEM_SAFE, true))
            || (in_array('photo', $kinds, true) && ! in_array($base, self::SYSTEM_SAFE, true) && ! in_array($base, self::PHOTO_EXTRA, true))
            || (in_array('root', $kinds, true) && ! in_array($base, self::ROOT_SAFE, true))) {
            $this->report->error($this->file, 'css_forbidden_property', in_array('root', $kinds, true)
                ? 'La raíz de la invitación (.inv-root, body, html, :root) solo admite color, fondo y tipografía.'
                : 'Un bloque del sistema solo admite propiedades visuales.', $line, $name);

            return;
        }
        if (in_array('bare', $kinds, true) && ! in_array($base, self::SYSTEM_SAFE, true) && ! in_array($base, self::BARE_EXTRA, true)) {
            $this->report->error($this->file, 'css_forbidden_property', 'Un selector sin clase (etiqueta o *) solo admite propiedades visuales; usa una clase del paquete.', $line, $name);

            return;
        }
        if ($this->restricted($kinds) !== []) {
            $this->restrictedCheck($d, $base, $kinds, $line);
        }
        if (str_starts_with($base, 'animation') || str_starts_with($base, 'transition')) {
            // A computed or variable duration makes the 4 s cap meaningless (W6-07), and a variable could name a keyframe.
            if (array_intersect($this->functionNames($d->getValue()), ['calc', 'var', 'env', 'attr', 'min', 'max', 'clamp']) !== []) {
                $this->report->error($this->file, 'css_forbidden_property', 'animation y transition solo admiten valores literales (sin calc(), var(), min(), max() ni clamp()).', $line, $base);
            }
        }

        if (is_string($d->getValue()) && in_array($base, ['animation', 'animation-name'], true) && isset($this->keyframes[$d->getValue()])) {
            $this->noteKeyframeUse($d->getValue());
            $d->setValue($this->keyframes[$d->getValue()]);
        }
        $this->value($d->getValue(), $base, $line);
        $this->specialCases($d, $base, $kinds, $line);
    }

    private function noteKeyframeUse(string $name): void
    {
        if ($this->restricted($this->ruleKinds) !== []) {
            $this->restrictedKeyframes[$name] = true;
        }
    }

    /**
     * Everything that can hide, disable, shrink, move or cover a system block when the rule can reach one (root, wrapper,
     * system hook, photo, generic selector). Values are judged by what they are: `calc()` / `var()` never, zero or
     * near-zero in every spelling, displacements, transparent colors and gigantic shadows.
     *
     * @param  list<string>  $kinds
     */
    private function restrictedCheck(Declaration $d, string $base, array $kinds, ?int $line): void
    {
        $value = $d->getValue();
        $text = $this->valueText($d);
        $deny = fn (string $why) => $this->report->error($this->file, 'css_forbidden_property', $why, $line, "{$base}: {$text}");
        $fns = $this->functionNames($value);
        $wrapper = in_array('wrapper', $kinds, true);
        $photo = in_array('photo', $kinds, true);

        $hiding = ['clip-path', 'mask', 'mask-image', 'mask-size', 'mask-position', 'mask-repeat', 'mask-mode', 'mask-composite', 'mask-origin', 'mask-clip', 'filter', 'backdrop-filter', 'pointer-events'];
        $photoHiding = ['backdrop-filter', 'pointer-events'];
        if (($wrapper && in_array($base, [...$hiding, ...self::WRAPPER_FORBIDDEN], true))
            || (! $wrapper && $photo && in_array($base, $photoHiding, true))
            || (in_array('bare', $kinds, true) && in_array($base, [...$hiding, ...self::WRAPPER_FORBIDDEN], true))) {
            $deny('Un bloque del sistema, o lo que lo contiene, no puede ocultarse, moverse ni deshabilitarse.');

            return;
        }

        if ($base === 'display' && ! in_array($text, self::VISIBLE_DISPLAY, true)) {
            $deny('display solo admite valores que muestran el elemento (block, flex, grid, inline...).');
        }
        if ($base === 'visibility' && $text !== 'visible') {
            $deny('visibility solo admite visible.');
        }
        if ($base === 'position' && ! in_array($text, $photo && ! $wrapper ? ['relative', 'absolute', 'static'] : ['relative', 'static'], true)) {
            $deny('position solo admite relative o static aquí.');
        }
        if ($base === 'opacity' && ! $this->opacityOk($value)) {
            $deny('La opacidad debe ser un número o porcentaje literal de 0.6 (60%) o más; sin calc() ni var().');
        }
        if (in_array($base, ['color', 'text-fill-color', 'text-stroke-color'], true)) {
            if (in_array('var', $fns, true) && ! $this->onlyEngineVars($text) || in_array('color-mix', $fns, true) || $this->transparentColor($text)) {
                $deny('El texto no puede ser transparente ni depender de una variable propia.');
            }
        }
        if (in_array($base, self::SHRINKABLE, true)) {
            if (array_intersect($fns, ['calc', 'var', 'env', 'attr']) !== [] || ! $this->floorOk($value, $base)) {
                $deny('Tamaño cero o casi cero, o expresado con calc()/var(): un bloque del sistema no puede encogerse.');
            }
        }
        if (preg_match('/^(margin|padding)(-|$)|^(row-|column-)?gap$/', $base)) {
            $engineOnly = $this->onlyEngineVars($text);
            $banned = ['calc', 'env', 'attr'];
            if (str_starts_with($base, 'margin')) {
                $banned[] = 'var';
            }
            if (array_intersect($fns, $banned) !== [] || (in_array('var', $fns, true) && ! $engineOnly && str_starts_with($base, 'margin'))) {
                $deny('Márgenes sin calc() ni var(); el espaciado interior solo admite valores literales o variables.');
            }
            foreach ($this->sizes($value) as $size) {
                $v = $size->getSize();
                $u = strtolower((string) $size->getUnit());
                if (($v < 0 && str_starts_with($base, 'margin')) || abs($v) > match ($u) {
                    'px' => 600, 'em', 'rem' => 40, '%' => 30, 'vw', 'vh', 'vmin', 'vmax', 'svh', 'dvh', 'lvh' => 30, default => 600,
                }) {
                    $deny('Espaciado negativo o desmesurado: podría sacar un bloque de la pantalla.');
                    break;
                }
            }
        }
        if (in_array($base, ['letter-spacing', 'word-spacing'], true)) {
            foreach ($this->sizes($value) as $size) {
                if (abs($size->getSize()) > (in_array(strtolower((string) $size->getUnit()), ['em', 'rem'], true) ? 2 : 20)) {
                    $deny('Espaciado de letras desmesurado.');
                    break;
                }
            }
            if (array_intersect($fns, ['calc', 'var']) !== []) {
                $deny('letter-spacing sin calc() ni var().');
            }
        }
        if (in_array($base, ['box-shadow', 'text-shadow', 'outline', 'outline-width', 'outline-offset'], true)) {
            if (array_intersect($fns, ['calc', 'var']) !== [] && ! $this->onlyEngineVars($text)) {
                $deny('Sombras y contornos sin calc() ni var() propias.');
            }
            foreach ($this->sizes($value) as $size) {
                if (abs($size->getSize()) > (in_array(strtolower((string) $size->getUnit()), ['em', 'rem'], true) ? 8 : 100)) {
                    $deny('Sombra o contorno desmesurado: cubriría a los bloques vecinos.');
                    break;
                }
            }
        }
    }

    /** @return list<string> lower-case names of the CSS functions in a value */
    private function functionNames(mixed $value): array
    {
        $names = [];
        if ($value instanceof CSSFunction) {
            $names[] = strtolower($value->getName());
        }
        if ($value instanceof ValueList) {
            foreach ($value->getListComponents() as $c) {
                array_push($names, ...$this->functionNames($c));
            }
        }

        return $names;
    }

    /** @return list<Size> */
    private function sizes(mixed $value): array
    {
        if ($value instanceof Size) {
            return [$value];
        }
        $out = [];
        if ($value instanceof ValueList && ! $value instanceof URL) {
            foreach ($value->getListComponents() as $c) {
                array_push($out, ...$this->sizes($c));
            }
        }

        return $out;
    }

    private function onlyEngineVars(string $text): bool
    {
        return preg_match_all('/var\(\s*(--[a-z0-9-]+)/', $text, $m) > 0 && array_filter($m[1], fn ($v) => ! str_starts_with($v, '--inv-')) === [];
    }

    private function opacityOk(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['inherit', 'initial', 'unset', 'revert'], true);
        }
        if (! $value instanceof Size) {
            return false;
        }
        $unit = strtolower((string) $value->getUnit());

        return ($unit === '' && $value->getSize() >= 0.6 && $value->getSize() <= 1) || ($unit === '%' && $value->getSize() >= 60 && $value->getSize() <= 100);
    }

    /** True when the (lower-case) color text is, or may be, fully or mostly transparent. */
    private function transparentColor(string $text): bool
    {
        if (preg_match('/\btransparent\b/', $text)) {
            return true;
        }
        if (preg_match_all('/#([0-9a-f]{4}|[0-9a-f]{8})\b/', $text, $m)) {
            foreach ($m[1] as $hex) {
                $alpha = strlen($hex) === 4 ? hexdec($hex[3] . $hex[3]) : hexdec(substr($hex, 6, 2));
                if ($alpha / 255 < 0.5) {
                    return true;
                }
            }
        }
        if (preg_match_all('/(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(([^)]*)\)/', $text, $m)) {
            foreach ($m[1] as $inner) {
                $parts = preg_split('/[\s,\/]+/', trim($inner), -1, PREG_SPLIT_NO_EMPTY);
                if (count($parts) >= 4) {
                    $a = end($parts);
                    $alpha = str_ends_with($a, '%') ? (float) $a / 100 : (float) $a;
                    if ($alpha < 0.5) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** False when the value is zero or nearly zero in any spelling: literal, `min()`, `max()` or `clamp()` of literals. */
    private function floorOk(mixed $value, string $prop): bool
    {
        if ($value instanceof Size) {
            return ! $this->tiny($value, $prop);
        }
        if (is_string($value)) {
            return true; // keywords: auto, none, fit-content, inherit...
        }
        if ($value instanceof CSSFunction) {
            $name = strtolower($value->getName());
            $args = array_values(array_filter($value->getListComponents(), fn ($c) => $c !== ','));
            foreach ($args as $a) {
                if (! $a instanceof Size) {
                    return false; // nested function, var(), string...
                }
            }
            $ok = array_map(fn (Size $a) => ! $this->tiny($a, $prop), $args);

            return match ($name) {
                'min' => ! in_array(false, $ok, true),
                'max' => in_array(true, $ok, true),
                'clamp' => ($ok[0] ?? false) === true,
                default => false,
            };
        }
        if ($value instanceof ValueList) {
            foreach ($value->getListComponents() as $c) {
                if (! $this->floorOk($c, $prop)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function tiny(Size $s, string $prop): bool
    {
        $v = abs($s->getSize());
        $text = $prop === 'font-size';
        $line = $prop === 'line-height';

        return match (strtolower((string) $s->getUnit())) {
            '' => $line ? $v < 0.8 : $v < 0.0001,
            'px' => $v < 8,
            'em', 'rem', 'ex', 'ch' => $v < ($line ? 0.8 : 0.5),
            '%' => $v < ($text ? 50 : ($line ? 80 : 5)),
            'vw', 'vh', 'vmin', 'vmax', 'svh', 'dvh', 'lvh', 'svw', 'dvw', 'lvw' => $v < 2,
            default => false,
        };
    }

    private function specialCases(Declaration $d, string $base, array $kinds, ?int $line): void
    {
        $text = $this->valueText($d);
        $restricted = $this->restricted($kinds) !== [];

        switch ($base) {
            case 'position':
                if (! in_array($text, ['relative', 'absolute', 'static'], true)) {
                    $this->report->error($this->file, 'css_forbidden_property', 'position solo admite relative, absolute o static.', $line, $text);
                }
                break;
            case 'display':
                if ($text === 'none' && $restricted) {
                    $this->report->error($this->file, 'css_forbidden_property', 'No se puede ocultar un bloque del sistema ni un selector genérico.', $line, $text);
                }
                break;
            case 'visibility':
                if ($text !== 'visible') {
                    $this->report->error($this->file, 'css_forbidden_property', 'visibility solo admite visible.', $line, $text);
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
                if ($restricted) {
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
                if ($base === 'mix-blend-mode' && ++$this->blendRules > 1) {
                    $this->report->error($this->file, 'css_forbidden_property', 'mix-blend-mode solo se admite en una regla del paquete.', $line, $text);
                }
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
                    $this->noteKeyframeUse($component);
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
        if (in_array($name, ['blur', 'drop-shadow'], true)) {
            foreach ($this->sizes($fn) as $size) {
                $unit = strtolower((string) $size->getUnit());
                $px = match ($unit) {
                    'px', '' => $size->getSize(),
                    'em', 'rem' => $size->getSize() * 16,
                    default => 1_000_000.0, // %, vw, vh...: unbounded for a filter
                };
                if (abs($px) > ($name === 'blur' ? 40 : 100)) {
                    $this->report->error($this->file, 'css_forbidden_property', $name === 'blur' ? 'blur() admite como máximo 40px.' : 'drop-shadow() admite como máximo 100px.', $line, $name . '(' . $size->getSize() . $unit . ')');

                    return false;
                }
            }
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
        // Text kept in a --pkg-* variable is inert (content only accepts none/""), so it only gets the character check.
        if (str_starts_with($property, '--pkg-') && ! preg_match('/[<>{}]|url\(/i', $value)) {
            return;
        }
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
        $unit = strtolower((string) $size->getUnit());
        $value = abs($size->getSize());
        if (in_array($property, self::DURATION_PROPERTIES, true) && in_array($unit, ['s', 'ms'], true)) {
            $ms = $value * ($unit === 's' ? 1000 : 1);
            if ($ms > 4000) {
                $this->report->error($this->file, 'css_forbidden_property', 'Las duraciones y retardos no pueden pasar de 4 s (en positivo ni en negativo).', $line, $size->getSize() . $size->getUnit());
            }

            return;
        }
        // Absolute sizes are bounded (W6-07): a 99999px box or blur costs a phone its graphics memory. Pill radii are exempt.
        if (str_contains($property, 'radius')) {
            return;
        }
        $limit = match ($unit) {
            'px' => 4000,
            'em', 'rem' => 250,
            'vw', 'vh', 'vmin', 'vmax', 'svh', 'dvh', 'lvh', 'svw', 'dvw', 'lvw', 'cqw', 'cqh', '%' => 1000,
            default => null,
        };
        if ($limit !== null && $value > $limit) {
            $this->report->error($this->file, 'css_forbidden_property', "Tamaño fuera de límites (máximo {$limit}{$unit}).", $line, $size->getSize() . $size->getUnit());
        }
    }

    private function lineOf(string $text, int $offset): int
    {
        return substr_count($text, "\n", 0, $offset) + 1;
    }
}
