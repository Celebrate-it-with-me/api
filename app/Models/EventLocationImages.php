<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventLocationImages extends Model
{
    /** @use HasFactory<\Database\Factories\EventLocationFactory> */
    use HasFactory;

    protected $table = 'event_location_images';

    protected $fillable = [
        'event_location_id',
        'path',
        'caption',
        'order',
        'source',
    ];

    /**
     * Define a relationship to the Events model.
     */
    public function eventLocation(): BelongsTo
    {
        return $this->belongsTo(EventLocation::class, 'event_id', 'id');
    }
}
