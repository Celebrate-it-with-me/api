<?php

namespace App\Http\Services\Admin\Templates\Packages;

use App\Http\Services\Admin\AdminAudit;
use App\Models\TemplatePackage;
use App\Support\TemplatePackage\TemplatePackageValidator;
use App\Support\TemplatePackage\ValidationContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploads waiting to be installed. A zip is validated once; the verdict (report included) and, when it is valid, the
 * sanitized result wait under a token for `stage_hours`: the private metadata on the local disk, the public files
 * (sanitized, never the original bytes) under `_staging/<token>/` so the preview can load them. Installing moves them.
 */
class PackageStage
{
    public const LOCK = 'template-package-install';

    /** Seconds the install lock lives if the process dies before releasing it (W6-06: it used to be 120). */
    public const LOCK_SECONDS = 30;

    public function __construct(private readonly PackageFiles $files, private readonly AdminAudit $audit, private readonly PackageThumbnail $thumbnails) {}

    /** @return array{valid: bool, stageToken: string, expiresAt: string, report: array} */
    public function validate(UploadedFile $zip): array
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw PackageError::conflict('install_busy', 'Otra instalación de paquete está en curso. Inténtalo de nuevo en unos segundos.');
        }

        $previousLimit = (int) ini_get('max_execution_time');
        try {
            // Validation has its own ceiling, below the lock's life, so a slow zip cannot hold the lock after it expired.
            @set_time_limit(self::LOCK_SECONDS - 5);
            $path = (string) $zip->getRealPath();
            $result = (new TemplatePackageValidator)->validate($path, $this->context());
            $zipSha = (string) hash_file('sha256', $path);

            return $this->store($result, $zipSha, (int) $zip->getSize());
        } finally {
            @set_time_limit($previousLimit); // the ceiling is for this validation only, not for the worker's next job
            $lock->release();
        }
    }

    /** @return array<string, mixed> the stored metadata of a token; 404 when it does not exist, 410 when it expired */
    public function load(string $token): array
    {
        $meta = preg_match('/^[0-9a-z]{26}$/', $token) === 1 ? $this->readMeta($token) : null;
        if ($meta === null) {
            throw PackageError::notFound('stage_not_found', 'La subida no existe o ya se instaló.');
        }
        if (now()->gte($meta['expiresAt'])) {
            $this->discard($token);
            throw PackageError::gone('stage_expired', 'La subida caducó: vuelve a subir el paquete.');
        }
        // A stage belongs to the admin who uploaded it (W6-13): the token alone is not enough to install, preview or discard it.
        if (($meta['uploadedBy'] ?? null) === null || $meta['uploadedBy'] !== request()->user()?->id) {
            throw PackageError::make(403, 'stage_forbidden', 'Esta subida la hizo otra persona del equipo: súbela de nuevo.');
        }

        return $meta;
    }

    public function discard(string $token): void
    {
        if (preg_match('/^[0-9a-z]{26}$/', $token) !== 1) {
            return;
        }
        $this->files->delete(PackageFiles::STAGING . "/{$token}");
        $this->thumbnails->discardStaged($token);
        $this->local()->delete($this->metaPath($token));
    }

    /** Removes the stages older than their lifetime (scheduled). @return int how many were removed */
    public function purgeExpired(): int
    {
        $removed = 0;
        foreach ($this->local()->files('template-staging') as $file) {
            $token = basename($file, '.json');
            $meta = $this->readMeta($token);
            if ($meta === null || now()->gte($meta['expiresAt'])) {
                $this->discard($token);
                $removed++;
            }
        }

        // Public folders whose metadata is gone (a crash between the two writes).
        foreach ($this->files->disk()->directories(PackageFiles::STAGING) as $dir) {
            $token = basename($dir);
            if (! $this->local()->exists($this->metaPath($token))) {
                $this->files->delete($dir);
                $removed++;
            }
        }

        return $removed;
    }

    /** What the validator must know about what is already installed. */
    public function context(): ValidationContext
    {
        $installed = [];
        $levels = [];
        $visibilities = [];
        $previous = [];

        TemplatePackage::query()->get(['id', 'slug', 'semver', 'level', 'visibility', 'editable', 'manifest'])
            ->groupBy('slug')
            ->each(function ($rows, string $slug) use (&$installed, &$levels, &$visibilities, &$previous) {
                $top = $rows->sort(fn ($a, $b) => version_compare($b->semver, $a->semver))->first();
                $installed[$slug] = $top->semver;
                $levels[$slug] = (int) $top->level;
                $visibilities[$slug] = $top->visibility;
                $previous[$slug] = [
                    'fields' => array_values(array_filter(array_column((array) ($top->editable['fields'] ?? []), 'id'), 'is_string')),
                    'variants' => collect((array) ($top->manifest['variants'] ?? []))->map(fn ($v) => (array) ($v['values'] ?? []))->all(),
                ];
            });

        return new ValidationContext($installed, $levels, $visibilities, $previous);
    }

    /**
     * @param  array{valid: bool, report: array, artifacts: array}  $result
     * @return array{valid: bool, stageToken: string, expiresAt: string, report: array}
     */
    private function store(array $result, string $zipSha, int $zipBytes): array
    {
        $token = strtolower((string) Str::ulid());
        $expires = now()->addHours((int) config('invitation.packages.stage_hours'));
        $meta = [
            'valid' => $result['valid'],
            'expiresAt' => $expires->toIso8601String(),
            'uploadedBy' => request()->user()?->id,
            'zipSha256' => $zipSha,
            'zipBytes' => $zipBytes,
            'report' => $result['report'],
        ];

        if ($result['valid']) {
            $build = PackageBuild::from($result);
            $meta['build'] = [
                'contentHash' => $build['contentHash'],
                'ast' => $build['ast'],
                'files' => $build['files'],
                'json' => $build['json'],
            ];
            $this->files->write(PackageFiles::STAGING . "/{$token}", $build['public']);
            // The selector's thumbnail comes from the mobile capture, which is not part of the published files (D-5).
            $this->thumbnails->stage($token, $result['artifacts']['previewMobile'] ?? null);
            $this->audit->record('template.package_validated', ['template_package', null], [
                'after' => [
                    'slug' => $build['json']['manifest']['slug'] ?? null,
                    'version' => $build['json']['manifest']['version'] ?? null,
                    'zipSha256' => $zipSha,
                    'contentHash' => $build['contentHash'],
                    'warnings' => array_values(array_unique(array_column($result['report']['warnings'], 'code'))),
                ],
            ]);
        } else {
            $this->audit->record('template.package_rejected', ['template_package', null], [
                'after' => ['zipSha256' => $zipSha, 'codes' => $result['report']['errors'] === [] ? [] : array_values(array_unique(array_column($result['report']['errors'], 'code')))],
            ]);
        }
        // Throws instead of writing an empty file the install could never read (W6-11).
        $this->local()->put($this->metaPath($token), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if ($result['valid'] && $meta['uploadedBy'] !== null) {
            $this->enforceQuota($meta['uploadedBy'], $token);
        }

        return ['valid' => $result['valid'], 'stageToken' => $token, 'expiresAt' => $meta['expiresAt'], 'report' => $result['report']];
    }

    /**
     * Each valid upload leaves a public copy on disk until it is installed, discarded or expired: an admin keeps at most
     * `max_stages_per_admin` of them, the oldest ones are discarded (W6-14).
     */
    private function enforceQuota(int $adminId, string $keep): void
    {
        $max = max(1, (int) config('invitation.packages.max_stages_per_admin'));
        $mine = [];
        foreach ($this->local()->files('template-staging') as $file) {
            $token = basename($file, '.json');
            $meta = $this->readMeta($token);
            if ($meta !== null && ($meta['uploadedBy'] ?? null) === $adminId && ($meta['valid'] ?? false)) {
                $mine[$token] = $meta['expiresAt'];
            }
        }
        asort($mine);
        foreach (array_keys($mine) as $token) {
            if (count($mine) <= $max) {
                break;
            }
            if ($token !== $keep) {
                $this->discard($token);
                unset($mine[$token]);
            }
        }
    }

    private function local(): Filesystem
    {
        return Storage::disk(config('invitation.packages.staging_disk'));
    }

    private function metaPath(string $token): string
    {
        return "template-staging/{$token}.json";
    }

    /** @return array<string, mixed>|null */
    private function readMeta(string $token): ?array
    {
        $raw = $this->local()->get($this->metaPath($token));
        $meta = $raw === null ? null : json_decode($raw, true);

        return is_array($meta) && isset($meta['expiresAt']) ? $meta : null;
    }
}
