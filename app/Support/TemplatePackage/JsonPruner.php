<?php

namespace App\Support\TemplatePackage;

/**
 * Keeps only the keys of a package document that the contract declares. Whatever else the author wrote (including
 * `__proto__`, `constructor` and `prototype`, and 64 KB of free text under an invented key) is dropped before
 * validation, so it is neither stored nor returned to the admin nor read by the renderer (W6-12).
 *
 * A shape is `true` (keep as it is), or an array `key => shape`; the key `*` applies to every other key or element.
 */
final class JsonPruner
{
    private const DANGEROUS = ['__proto__', 'constructor', 'prototype'];

    private const LABEL = ['es' => true, 'en' => true];

    /** @return array<string, mixed>|true|null */
    private static function shapeFor(string $file): array|bool|null
    {
        return match ($file) {
            'manifest.json' => [
                'schema' => true, 'slug' => true, 'name' => self::LABEL, 'description' => self::LABEL, 'version' => true,
                'level' => true, 'eventTypes' => true, 'tier' => true, 'visibility' => true, 'engineMin' => true,
                'author' => ['name' => true, 'url' => true],
                'license' => ['id' => true, 'summary' => true, 'document' => true],
                'thirdPartyMarks' => ['*' => ['name' => true, 'owner' => true, 'usage' => true, 'url' => true]],
                'assetsCredits' => ['*' => ['path' => true, 'title' => true, 'author' => true, 'source' => true, 'license' => true, 'url' => true]],
                'variants' => ['*' => ['values' => true, 'default' => true]],
            ],
            'tokens.json' => [
                'schema' => true, 'preset' => true,
                'colors' => ['*' => true],
                'fonts' => ['*' => ['family' => true, 'files' => ['*' => ['file' => true, 'weight' => true, 'style' => true]]]],
                'presets' => ['*' => ['label' => self::LABEL, 'colors' => ['*' => true]]],
            ],
            'editable.json' => [
                'schema' => true,
                'groups' => ['*' => ['id' => true, 'label' => self::LABEL]],
                'fields' => ['*' => [
                    'id' => true, 'type' => true, 'group' => true, 'label' => self::LABEL, 'help' => self::LABEL,
                    'bind' => ['section' => true, 'setting' => true, 'focus' => true, 'field' => true, 'theme' => true, 'option' => true],
                    'aspect' => true, 'minWidth' => true, 'required' => true, 'plan' => true, 'fallbackSlot' => true,
                    'multiline' => true, 'max' => self::LABEL, 'default' => true,
                    'choices' => ['*' => ['value' => true, 'label' => self::LABEL]],
                ]],
            ],
            'strings.json' => ['es' => ['*' => true], 'en' => ['*' => true]],
            default => null, // sections.json is validated by TemplateSchema; only the dangerous keys go
        };
    }

    public static function forFile(string $file, mixed $data): mixed
    {
        $shape = self::shapeFor($file);

        return self::prune($data, $shape ?? true);
    }

    private static function prune(mixed $data, mixed $shape): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::DANGEROUS, true)) {
                continue;
            }
            if ($shape === true) {
                $out[$key] = self::prune($value, true);

                continue;
            }
            $sub = is_array($shape) && array_key_exists($key, $shape) ? $shape[$key] : ($shape['*'] ?? null);
            if ($sub === null || ! is_array($shape)) {
                continue;
            }
            $out[$key] = self::prune($value, $sub);
        }

        return $out;
    }

    /** True when the document carries a number that is not finite (`1e999` decodes to INF). */
    public static function hasNonFinite(mixed $data): bool
    {
        if (is_float($data)) {
            return ! is_finite($data);
        }
        if (is_array($data)) {
            foreach ($data as $v) {
                if (self::hasNonFinite($v)) {
                    return true;
                }
            }
        }

        return false;
    }
}
