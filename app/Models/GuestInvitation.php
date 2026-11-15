<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestInvitation extends Model
{
    protected $table = 'guest_invitations';

    protected $fillable = [
        'guest_id',
        'channel',
        'sent_at',
        'status',
        'message_preview',
        'response_payload',
        'attempted_by',
    ];

    /**
     * Get the guest that this invitation belongs to.
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id', 'id');
    }
}
