<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One immutable snapshot of a template's sections and theme. Only a draft can be edited or deleted; a published
 * version can only move to archived (and nothing else changes), an archived one never changes again.
 */
class InvitationTemplateVersion extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    public const SCHEMA_VERSION = 1;

    protected $fillable = [
        'template_id', 'version', 'status', 'sections', 'theme', 'schema_version', 'notes',
        'created_by', 'published_by', 'published_at', 'archived_at',
    ];

    protected $casts = [
        'sections' => 'array',
        'theme' => 'array',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            $was = $version->getOriginal('status');

            if ($was === self::DRAFT) {
                return;
            }

            $onlyArchiving = $was === self::PUBLISHED
                && $version->status === self::ARCHIVED
                && array_diff(array_keys($version->getDirty()), ['status', 'archived_at', 'updated_at']) === [];

            if (! $onlyArchiving) {
                throw new LogicException('A version that is not a draft is immutable.');
            }
        });

        static::deleting(function (self $version) {
            if ($version->status !== self::DRAFT) {
                throw new LogicException('Only a draft version can be deleted.');
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(InvitationTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Events::class, 'template_version_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
