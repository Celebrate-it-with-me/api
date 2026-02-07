<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventFeature extends Model
{
    /** @use HasFactory<\Database\Factories\EventsFactory> */

    protected $table = 'event_features';

    protected $fillable = [
        'event_id',
        'save_the_date',
        'rsvp',
        'menu',
        'sweet_memories',
        'music',
        'background_music',
        'event_comments',
        'seats_accommodation',
        'preview',
        'budget',
        'analytics',
        'location'
    ];

    /**
     * Define a relationship to the Events model.
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
