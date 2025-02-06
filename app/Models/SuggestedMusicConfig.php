<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuggestedMusicConfig extends Model
{
    /** @use HasFactory<\Database\Factories\SuggestedMusicConfigFactory> */
    use HasFactory;
    
    protected $table = 'suggested_music_configs';
    
    protected $fillable = [
        'event_id',
        'title',
        'sub_title',
        'main_color',
        'secondary_color',
        'use_preview',
        'use_vote_system',
        'search_limit'
    ];
    
    /**
     * Defines the relationship between the current model and the Events model.
     * This indicates that the current model belongs to an event.
     *
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
