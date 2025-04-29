<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventLocation extends Model
{
    /** @use HasFactory<\Database\Factories\EventLocationFactory> */
    use HasFactory;
    
    protected $table = 'event_locations';
    
    protected $fillable = [
        'event_id',
        'name',
        'address',
        'city',
        'state',
        'zip_code',
        'country',
        'latitude',
        'longitude',
        'is_default'
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
