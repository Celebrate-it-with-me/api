<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timeline extends Model
{
    /** Kinds of moment; each one has its own icon in the app. */
    public const TYPES = ['ceremony', 'reception', 'waltz', 'dinner', 'dance', 'toast', 'other'];

    protected $table = 'timelines';

    protected $fillable = ['event_id', 'title', 'type', 'description', 'start_time', 'end_time'];

    protected $casts = ['start_time' => 'datetime', 'end_time' => 'datetime'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }

    /** The shape the screens and the invitation read. Times are the event's local time, without zone. */
    public function toMoment(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'description' => $this->description,
            'startTime' => $this->start_time->format('Y-m-d H:i:s'),
            'endTime' => $this->end_time?->format('Y-m-d H:i:s'),
        ];
    }
}
