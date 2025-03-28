<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaveTheDate extends Model
{
    /** @use HasFactory<\Database\Factories\SaveTheDateFactory> */
    use HasFactory;
    
    protected $table = 'save_the_date';
    
    protected $fillable = [
        'event_id',
        'std_title',
        'std_subtitle',
        'background_color',
        'image_url',
        'use_countdown',
        'use_add_to_calendar',
        'is_enabled'
    ];
    
    /**
     * Define a relationship to the Events model.
     *
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
