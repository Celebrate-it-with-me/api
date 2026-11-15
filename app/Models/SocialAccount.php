<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'avatar',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    /** Tokens never leave the model in clear text by accident (S-28). */
    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    /**
     * Defines a relationship indicating that this model belongs to a single User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
