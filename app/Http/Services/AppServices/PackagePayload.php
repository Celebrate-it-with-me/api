<?php

namespace App\Http\Services\AppServices;

use App\Http\Services\Admin\Templates\Packages\MissingPackagesUrl;
use App\Http\Services\Admin\Templates\Packages\PackageFiles;
use App\Models\TemplatePackage;
use Illuminate\Support\Facades\Cache;

/**
 * What the public invitation needs from an installed package (`data.theme.package` and the AST of each section).
 *
 * The package never changes once installed, so the fragment without URLs is cached forever by package id; URLs are
 * composed on every request from the disk (changing the CDN never rewrites a stored row). A package that is
 * withdrawn, missing or newer than the engine yields null: the invitation then falls back to Classic (spec 5.4).
 */
class PackagePayload
{
    public function __construct(private readonly PackageFiles $files) {}

    /**
     * The package pinned by a version, ready for the payload; null when it cannot be used.
     *
     * @param  array<string, mixed>  $eventOptions  `template_config.package.options` of the event
     * @return array{package: array<string, mixed>, sections: array<string, array>}|null
     */
    public function forPackageId(?int $packageId, array $eventOptions = []): ?array
    {
        if ($packageId === null) {
            return null;
        }
        $state = TemplatePackage::query()->whereKey($packageId)->first(['id', 'status', 'engine_min']);
        if ($state === null || $state->isWithdrawn()) {
            return null;
        }

        return $this->compose($this->fragment($packageId), $eventOptions);
    }

    /**
     * @param  array<string, mixed>  $eventOptions
     * @return array{package: array<string, mixed>, sections: array<string, array>}|null
     */
    public function compose(array $fragment, array $eventOptions = []): ?array
    {
        if ($fragment['engineMin'] > (int) config('invitation.engine')) {
            return null;
        }

        $allowed = array_intersect_key($eventOptions, $fragment['options']['known']);

        try {
            $cssUrl = $fragment['hasCss'] ? $this->files->url($fragment['folder'] . '/theme.css') : null;
        } catch (MissingPackagesUrl $e) {
            // No origin for the files: the invitation falls back to Classic instead of pointing at the API (W6-08).
            report($e);

            return null;
        }

        return [
            'package' => [
                'slug' => $fragment['slug'],
                'version' => $fragment['version'],
                'level' => $fragment['level'],
                'hash' => $fragment['hash'],
                'engineMin' => $fragment['engineMin'],
                'cssUrl' => $cssUrl,
                // Files to preload (fonts of the package, at most 6): the @font-face rules themselves are in the css.
                'preload' => array_slice(array_map(
                    fn (string $file) => ['url' => $this->files->url($fragment['folder'] . '/' . $file), 'as' => 'font', 'type' => 'font/woff2'],
                    $fragment['fontFiles']
                ), 0, 6),
                'strings' => $fragment['strings'],
                'options' => ($options = array_merge($fragment['options']['defaults'], $allowed)) === [] ? (object) [] : $options,
                'layout' => $fragment['layout'],
                // AST of every section the package draws, by type: the sections of the payload only say render:'package'.
                'templates' => $fragment['sections'] === [] ? (object) [] : $fragment['sections'],
            ],
            'sections' => $fragment['sections'],
        ];
    }

    /** The URL-free, immutable part of an installed package. @return array<string, mixed> */
    public function fragment(int $packageId): array
    {
        return Cache::rememberForever("invitation.package.{$packageId}", function () use ($packageId) {
            return $this->fragmentFrom(TemplatePackage::query()->findOrFail($packageId)->toArray(), null);
        });
    }

    /**
     * A staged (not installed yet) package, to preview it with the same payload an installed one would give.
     *
     * @param  array<string, mixed>  $meta  the stage metadata of a valid upload
     * @return array<string, mixed>
     */
    public function fragmentForStage(array $meta, string $token): array
    {
        $build = $meta['build'];
        $manifest = $build['json']['manifest'];

        return $this->fragmentFrom([
            'slug' => $manifest['slug'],
            'semver' => $manifest['version'],
            'level' => $manifest['level'],
            'engine_min' => $manifest['engineMin'],
            'content_hash' => $build['contentHash'],
            'tokens' => $build['json']['tokens'],
            'editable' => $build['json']['editable'],
            'strings' => $build['json']['strings'] ?? null,
            'ast' => $build['ast'],
            'files' => $build['files'],
            'manifest' => $manifest,
        ], PackageFiles::STAGING . "/{$token}");
    }

    /**
     * @param  array<string, mixed>  $p  a package row (or the same keys from a stage)
     * @return array<string, mixed>
     */
    private function fragmentFrom(array $p, ?string $folder): array
    {
        $fonts = [];
        foreach (['heading', 'body', 'script'] as $role) {
            $font = $p['tokens']['fonts'][$role] ?? null;
            if ($font && ($font['files'] ?? []) !== []) {
                $fonts[] = ['files' => array_column($font['files'], 'file')];
            }
        }

        return [
            'slug' => $p['slug'],
            'version' => $p['semver'],
            'level' => (int) $p['level'],
            'hash' => $p['content_hash'],
            'engineMin' => (int) $p['engine_min'],
            'folder' => $folder ?? "{$p['slug']}/{$p['semver']}-" . substr($p['content_hash'], 0, 16),
            'hasCss' => in_array('theme.css', array_column($p['files'], 'path'), true),
            'fontFiles' => array_values(array_unique(array_merge(...array_column($fonts, 'files') ?: [[]]))),
            'strings' => $p['strings'] ?? (object) [],
            'layout' => $p['ast']['layout'] ?? null,
            'sections' => $p['ast']['sections'] ?? [],
            'options' => $this->options($p['manifest'] ?? [], $p['editable'] ?? []),
        ];
    }

    /**
     * The options a package defines for its editor: defaults (from `variants` and from `default` of the fields bound to
     * an option) and the known ids an event may override.
     *
     * @return array{defaults: array<string, mixed>, known: array<string, true>}
     */
    private function options(array $manifest, array $editable): array
    {
        $defaults = [];
        $known = [];
        foreach ((array) ($manifest['variants'] ?? []) as $id => $variant) {
            $defaults[$id] = $variant['default'];
            $known[$id] = true;
        }
        foreach ((array) ($editable['fields'] ?? []) as $field) {
            $id = $field['bind']['option'] ?? null;
            if (is_string($id)) {
                $known[$id] = true;
                if (array_key_exists('default', $field)) {
                    $defaults[$id] = $field['default'];
                }
            }
        }

        return ['defaults' => $defaults, 'known' => $known];
    }
}
