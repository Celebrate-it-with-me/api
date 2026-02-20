<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timeline extends Model
{
    protected $table = 'timelines';
    
    protected $fillable = ['event_id', 'title', 'description', 'icon', 'start_time', 'end_time'];
    
    
    /**
     * Establishes a relationship where this model belongs to an Event.
     *
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }
}
