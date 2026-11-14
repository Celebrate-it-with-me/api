<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TOTP enrolment of one staff member. The secret is encrypted at rest (APP_KEY) and never serialized; recovery codes
 * are kept as keyed hashes in `recovery_codes` ([{hash, used_at}]), so not even the database can show them again.
 */
class AdminTwoFactor extends Model
{
    protected $table = 'admin_two_factor';

    protected $guarded = [];

    protected $hidden = ['secret', 'recovery_codes', 'last_used_step'];

    protected $casts = [
        'secret' => 'encrypted',
        'recovery_codes' => 'array',
        'confirmed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function recoveryCodesRemaining(): int
    {
        return collect($this->recovery_codes ?? [])->whereNull('used_at')->count();
    }
}
