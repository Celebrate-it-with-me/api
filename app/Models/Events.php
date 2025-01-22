<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Events extends Model
{
    /** @use HasFactory<\Database\Factories\EventsFactory> */
    use HasFactory;

    protected $table = 'events';

    protected $fillable = [
      'event_name',
      'event_description',
      'event_date',
      'organizer_id',
      'status',
      'custom_url_slug',
      'visibility'
    ];

    /**
     * Get the user that is the organizer of this event.
     *
     * @return BelongsTo
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id', 'id');
    }
}
