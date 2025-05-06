<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventType extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    
    protected $table = 'event_types';

    protected $fillable = [
        'name', 'slug', 'icon'
    ];
    
    /**
     * Define a one-to-many relationship with the Events model.
     *
     * @return HasMany
     */
    public function events(): HasMany
    {
        return $this->hasMany(Events::class, 'event_plan_id', 'id');
    }
    
}
