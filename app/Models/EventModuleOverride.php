<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff exception to what an event's plan says about one module (see `EventModules`). */
class EventModuleOverride extends Model
{
    protected $fillable = ['event_id', 'module_key', 'state', 'expires_at', 'reason', 'created_by'];

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
