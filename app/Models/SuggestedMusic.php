<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuggestedMusic extends Model
{
    protected $table = 'suggested_music';
    
    protected $fillable = [
        'event_id',
        'name',
        'platform',
        'platform_url',
        'suggested_by'
    ];
    
    /**
     * Define a relationship to the Events model.
     *
     * This function establishes a "Belongs To" relationship
     * with the Events model, associating the current model's
     * 'event_id' column with the 'id' column in the Events table.
     *
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
    
    /**
     * Define a relationship to the MainGuest model.
     *
     * This function establishes a "Belongs To" relationship
     * with the MainGuest model, linking the current model's
     * 'suggested_by' column to the 'id' column in the MainGuest table.
     *
     * @return BelongsTo
     */
    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(MainGuest::class, 'suggested_by', 'id');
    }
}
