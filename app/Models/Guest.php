<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    protected $table = 'guests';
    
    
    protected $fillable = [
        'event_id',
        'parent_id',
        'name',
        'email',
        'phone',
        'rsvp_status',
        'code',
        'notes',
        'is_vip',
        'tags'
    ];
    
    /**
     * Get the event that this guest belongs to.
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
    
    /**
     * Get the parent guest of this guest.
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'parent_id', 'id');
    }
    
    /**
     * Get the companions of this guest.
     * @return HasMany
     */
    public function companions(): HasMany
    {
        return $this->hasMany(Guest::class, 'parent_id', 'id');
    }
}
