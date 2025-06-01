<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SweetMemoriesConfig extends Model
{
    /** @use HasFactory<\Database\Factories\SuggestedMusicConfigFactory> */
    use HasFactory;

    protected $table = 'sweet_memories_config';

    protected $fillable = [
        'event_id',
        'title',
        'sub_title',
        'background_color',
        'max_pictures',
    ];

    /**
     * Defines the relationship between the current model and the Events model.
     * This indicates that the current model belongs to an event.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
