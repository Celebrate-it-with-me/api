<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaveTheDate extends Model
{
    use HasFactory;
    
    protected $table = 'save_the_dates';
    
    protected $fillable = [
        'event_id',
        'show_countdown',
        'use_add_to_calendar',
        'date_source',
        'custom_starts_at',
        'countdown_units',
        'countdown_finish_behavior',
        'calendar_providers',
        'calendar_mode',
        'calendar_location_override',
        'calendar_description_override',
    ];
    
    protected $casts = [
        'show_countdown' => 'boolean',
        'use_add_to_calendar' => 'boolean',
        'custom_starts_at' => 'datetime',
        'countdown_units' => 'array',
        'calendar_providers' => 'array',
    ];
    
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }
}
