<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaveTheDate extends Model
{
    /** @use HasFactory<\Database\Factories\SaveTheDateFactory> */
    use HasFactory;

    protected $table = 'save_the_dates';

    protected $fillable = [
        'event_id',
        'title',
        'message',
        'image_path',
        'video_url',
        'show_countdown',
        'use_add_to_calendar',
        'is_enabled',
    ];

    /**
     * Define a relationship to the Events model.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
