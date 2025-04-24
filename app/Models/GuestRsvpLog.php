<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestRsvpLog extends Model
{
    protected $table = 'guest_rsvp_logs';
    
    protected $fillable = [
        'guest_id',
        'status',
        'changed_at',
        'changed_by',
        'notes'
    ];
    
    /**
     * Get the guest that this log belongs to.
     * @return BelongsTo
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
