<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rsvp extends Model
{
    /** @use HasFactory<\Database\Factories\RsvpFactory> */
    use HasFactory;
    
    protected $table = 'rsvps';
    
    protected $fillable = [
        'event_id',
        'title',
        'description',
        'custom_fields',
        'confirmation_deadline'
    ];
    
    /**
     * Defines the relationship between this model and the Events model, establishing
     * a "belongs to" association using the event_id foreign key.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
