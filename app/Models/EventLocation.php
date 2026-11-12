<?php

namespace App\Models;

use Database\Factories\EventLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventLocation extends Model
{
    /** @use HasFactory<EventLocationFactory> */
    use HasFactory;

    /** Kinds of place. Ceremony and reception take their time from the program of the day. */
    public const TYPES = ['ceremony', 'reception', 'other'];

    public const MAX_IMAGES = 6;

    protected $table = 'event_locations';

    protected $fillable = [
        'event_id',
        'type',
        'name',
        'address',
        'city',
        'state',
        'zip_code',
        'country',
        'latitude',
        'longitude',
        'place_id',
        'notes', // shown as "directions" (parking, valet, entrance) in the screens
        'is_default',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }

    public function eventLocationImages(): HasMany
    {
        return $this->hasMany(EventLocationImages::class, 'event_location_id', 'id');
    }
}
