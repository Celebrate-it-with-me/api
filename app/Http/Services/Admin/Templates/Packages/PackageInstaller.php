<?php

namespace App\Http\Services\Admin\Templates\Packages;

use App\Http\Services\Admin\AdminAudit;
use App\Models\EventType;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion as Version;
use App\Models\TemplatePackage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Installs a staged, valid package: the sanitized files move to their immutable folder, the `template_packages` row is
 * created and, for every event type the package declares, a template (created when missing, inactive) gets a draft
 * version owned by the package. Nothing reaches an organizer until the package is activated.
 */
class PackageInstaller
{
    public function __construct(
        private readonly PackageStage $stage,
        private readonly PackageFiles $files,
        private readonly PackageQuery $query,
        private readonly AdminAudit $audit,
    ) {}

    /** @return array<string, mixed> the installed package */
    public function install(string $token, string $reason): array
    {
        $lock = Cache::lock('template-package-install', 120);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw PackageError::conflict('install_busy', 'Otra instalación de paquete está en curso. Inténtalo de nuevo en unos segundos.');
        }

        try {
            $meta = $this->stage->load($token);
            if (! $meta['valid']) {
                throw PackageError::invalid('stage_token', 'invalid_report', 'El informe de la subida tiene errores: no se puede instalar.');
            }
            $package = $this->persist($token, $meta, $reason);
        } finally {
            $lock->release();
        }

        return $this->query->show($package->id);
    }

    /** @param array<string, mixed> $meta */
    private function persist(string $token, array $meta, string $reason): TemplatePackage
    {
        $build = $meta['build'];
        $manifest = $build['json']['manifest'];
        $slug = $manifest['slug'];
        $semver = $manifest['version'];

        $existing = TemplatePackage::query()->where('slug', $slug)->where('semver', $semver)->first();
        if ($existing) {
            throw PackageError::conflict('package_exists', 'Esta versión del paquete ya está instalada.', ['packageId' => $existing->id]);
        }
        // The upload was validated against what was installed then; another install may have moved the bar since.
        $highest = TemplatePackage::query()->where('slug', $slug)->pluck('semver')->sort(fn ($a, $b) => version_compare($b, $a))->first();
        if ($highest !== null && version_compare($semver, $highest, '<=')) {
            throw PackageError::invalid('stage_token', 'stale_stage', "Se instaló otra versión mientras tanto ({$highest}): vuelve a subir el paquete.");
        }

        $folder = "{$slug}/{$semver}-" . substr($build['contentHash'], 0, 8);
        $this->files->copyFolder(PackageFiles::STAGING . "/{$token}", $folder);

        try {
            $package = DB::transaction(function () use ($meta, $build, $manifest, $folder) {
                $package = TemplatePackage::query()->create([
                    'slug' => $manifest['slug'],
                    'semver' => $manifest['version'],
                    'level' => $manifest['level'],
                    'visibility' => $manifest['visibility'] ?? TemplatePackage::PUBLIC,
                    'tier' => $manifest['tier'],
                    'status' => TemplatePackage::INSTALLED,
                    'engine_min' => $manifest['engineMin'],
                    'manifest' => $manifest,
                    'tokens' => $build['json']['tokens'],
                    'editable' => $build['json']['editable'],
                    'strings' => $build['json']['strings'] ?? null,
                    'ast' => $build['ast'],
                    'files' => $build['files'],
                    'report' => $meta['report'],
                    'content_hash' => $build['contentHash'],
                    'zip_sha256' => $meta['zipSha256'],
                    'storage_path' => $folder,
                    'size_bytes' => $meta['zipBytes'],
                    'installed_by' => request()->user()?->id,
                    'installed_at' => now(),
                ]);

                foreach ($manifest['eventTypes'] as $type) {
                    $this->draftFor($package, $type, $build['json']);
                }

                return $package;
            });
        } catch (\Throwable $e) {
            // All or nothing: the database rolled back, so the copied files must not stay behind.
            $this->files->delete($folder);
            throw $e;
        }

        $this->stage->discard($token);
        $this->audit->record('template.package_installed', ['template_package', $package->id], [
            'reason' => $reason,
            'after' => [
                'slug' => $package->slug,
                'version' => $package->semver,
                'level' => $package->level,
                'visibility' => $package->visibility,
                'contentHash' => $package->content_hash,
                'zipSha256' => $package->zip_sha256,
            ],
        ]);

        return $package;
    }

    /** The template of this package for one event type (created inactive when new) and its draft version. */
    private function draftFor(TemplatePackage $package, string $eventType, array $json): void
    {
        $manifest = $json['manifest'];
        $slug = "{$package->slug}-{$eventType}";
        $sections = $json['sections'][$eventType];
        $theme = PackageBuild::theme($package->slug, $json['tokens']);

        $template = InvitationTemplate::query()->where('slug', $slug)->lockForUpdate()->first()
            ?? InvitationTemplate::query()->create([
                'slug' => $slug,
                'name' => $manifest['name']['es'],
                'event_type_id' => EventType::query()->where('slug', $eventType)->value('id'),
                'tier' => $package->tier,
                'default_sections' => $sections,
                'theme_defaults' => $theme,
                'is_active' => false,
            ]);

        $draft = $template->versions()->where('status', Version::DRAFT)->first();
        if ($draft) {
            throw PackageError::conflict('draft_exists', "La plantilla «{$template->slug}» ya tiene un borrador: publícalo o archívalo antes.", [
                'templateId' => $template->id,
                'draftVersionId' => $draft->id,
            ]);
        }

        Version::query()->create([
            'template_id' => $template->id,
            'package_id' => $package->id,
            'version' => (int) $template->versions()->max('version') + 1,
            'status' => Version::DRAFT,
            'sections' => $sections,
            'theme' => $theme,
            'schema_version' => 2,
            'notes' => "Paquete {$package->slug} {$package->semver}",
            'created_by' => request()->user()?->id,
        ]);
    }
}
