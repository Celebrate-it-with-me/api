<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    public const LEVELS = ['info', 'news', 'warning'];

    public const AUDIENCES = ['all', 'free', 'paid', 'upcoming_event'];

    public const STATUSES = ['scheduled', 'active', 'ended', 'inactive'];

    /** Higher first when the app picks which three to show. */
    public const LEVEL_WEIGHT = ['warning' => 3, 'news' => 2, 'info' => 1];

    protected $fillable = [
        'level', 'title_es', 'title_en', 'body_es', 'body_en', 'cta_label_es', 'cta_label_en', 'cta_url',
        'dismissible', 'audience', 'upcoming_days', 'starts_at', 'ends_at', 'is_active', 'created_by',
    ];

    protected $casts = [
        'dismissible' => 'boolean',
        'is_active' => 'boolean',
        'upcoming_days' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function dismissals(): HasMany
    {
        return $this->hasMany(AnnouncementDismissal::class);
    }

    /** Computed on every read, never stored. */
    public function status(): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at !== null && ! $this->ends_at->isFuture() => 'ended',
            default => 'active',
        };
    }

    /** Active and inside its dates right now. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'inactive' => $query->where('is_active', false),
            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', now()),
            'ended' => $query->where('is_active', true)->where('starts_at', '<=', now())->whereNotNull('ends_at')->where('ends_at', '<=', now()),
            default => $query->live(),
        };
    }

    public function localized(string $lang, string $field): ?string
    {
        return $this->{$field . '_' . $lang};
    }
}
