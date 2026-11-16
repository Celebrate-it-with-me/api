<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One installed version of a template package. Immutable: the only things that change are `status` and its dates.
 * See docs/plans/template-package-spec.md, 6.1.
 */
class TemplatePackage extends Model
{
    public const INSTALLED = 'installed';

    public const ACTIVE = 'active';

    public const DEACTIVATED = 'deactivated';

    public const WITHDRAWN = 'withdrawn';

    public const PUBLIC = 'public';

    public const PRIVATE = 'private';

    protected $fillable = [
        'slug', 'semver', 'level', 'visibility', 'tier', 'status', 'engine_min', 'manifest', 'tokens', 'editable',
        'strings', 'ast', 'files', 'report', 'content_hash', 'zip_sha256', 'storage_path', 'size_bytes',
        'installed_by', 'installed_at', 'activated_at', 'deactivated_at', 'withdrawn_at',
    ];

    protected $casts = [
        'manifest' => 'array',
        'tokens' => 'array',
        'editable' => 'array',
        'strings' => 'array',
        'ast' => 'array',
        'files' => 'array',
        'report' => 'array',
        'installed_at' => 'datetime',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Content is immutable: only the lifecycle columns may change after the install.
        static::updating(function (self $package) {
            $changed = array_diff(array_keys($package->getDirty()), ['status', 'activated_at', 'deactivated_at', 'withdrawn_at', 'updated_at', 'storage_path']);
            if ($changed !== []) {
                throw new \LogicException('An installed template package is immutable: install a new version instead.');
            }
        });
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(InvitationTemplateVersion::class, 'package_id');
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::WITHDRAWN;
    }

    /** Folder of its files on the package disk: `<slug>/<version>-<hash8>`. */
    public function folder(): string
    {
        return "{$this->slug}/{$this->semver}-" . substr($this->content_hash, 0, 8);
    }

    /**
     * Font families the package declares with its own files, from the newest version that is not withdrawn; null when
     * no such package exists (the slug is not a theme).
     *
     * @return list<string>|null
     */
    public static function declaredFamilies(string $slug): ?array
    {
        $package = self::query()->where('slug', $slug)->where('status', '!=', self::WITHDRAWN)->orderByDesc('id')->first(['id', 'tokens']);
        if ($package === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($font) => is_array($font) ? ($font['family'] ?? null) : null,
            (array) ($package->tokens['fonts'] ?? [])
        ))));
    }
}
