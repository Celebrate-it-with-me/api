<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One time a staff member entered a user's account (docs/plans/admin-brief.md, A3). */
class ImpersonationSession extends Model
{
    public const ABILITY = 'impersonation';

    protected $guarded = [];

    protected $casts = [
        'notify' => 'boolean',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function supportRequest(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /** What the app receives to draw the banner. Never carries the staff member's name. */
    public function banner(): array
    {
        return [
            'sessionId' => $this->id,
            'expiresAt' => $this->expires_at->toIso8601String(),
            'staffLabel' => config('impersonation.staff_label'),
        ];
    }
}
