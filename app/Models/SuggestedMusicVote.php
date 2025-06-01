<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuggestedMusicVote extends Model
{
    protected $table = 'suggested_music_votes';

    protected $fillable = [
        'suggested_music_id',
        'main_guest_id',
        'vote_type',
    ];

    /**
     * Define a relationship to the Events model.
     *
     * This function establishes a "Belongs To" relationship
     * with the Events model, associating the current model's
     * 'event_id' column with the 'id' column in the Events table.
     */
    public function suggestedMusic(): BelongsTo
    {
        return $this->belongsTo(SuggestedMusic::class, 'suggested_music_id', 'id');
    }

    /**
     * Define a relationship to the MainGuest model.
     *
     * This function establishes a "Belongs To" relationship
     * with the MainGuest model, linking the current model's
     * 'suggested_by' column to the 'id' column in the MainGuest table.
     */
    public function mainGuest(): BelongsTo
    {
        return $this->belongsTo(MainGuest::class, 'main_guest_id', 'id');
    }
}
