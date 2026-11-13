<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A "Necesito ayuda" message from an organizer, with the context the app attached (A13). */
class SupportRequest extends Model
{
    public const STATUSES = ['new', 'in_progress', 'resolved'];

    protected $guarded = [];

    protected $casts = ['context' => 'array', 'resolved_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(SupportRequestNote::class, 'request_id')->orderBy('id');
    }
}
