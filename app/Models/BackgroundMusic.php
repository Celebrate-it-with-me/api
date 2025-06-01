<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackgroundMusic extends Model
{
    protected $table = 'background_music';

    protected $fillable = [
        'event_id',
        'icon_size',
        'icon_position',
        'icon_color',
        'auto_play',
        'song_url',
    ];

    /**
     * Define a relationship to the Events model.
     *
     * This function establishes a "Belongs To" relationship
     * with the Events model, associating the current model's
     * 'event_id' column with the 'id' column in the Events table.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
