<?php

namespace App\Models;

use App\Http\Services\AppServices\EventModules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff exception to what an event's plan says about one module (see `EventModules`). */
class EventModuleOverride extends Model
{
    protected $fillable = ['event_id', 'module_key', 'state', 'expires_at', 'reason', 'created_by'];

    /** A change here changes what every event's menu shows: drop the cached module reads (see EventModules). */
    protected static function booted(): void
    {
        static::saved(fn () => app(EventModules::class)->invalidate());
        static::deleted(fn () => app(EventModules::class)->invalidate());
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** Not expired: no expiry, or one in the future. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
