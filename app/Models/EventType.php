<?php

namespace App\Models;

use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventType extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory;

    protected $table = 'event_types';

    protected $fillable = [
        'name', 'slug', 'icon', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Define a one-to-many relationship with the Events model.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Events::class, 'event_type_id', 'id');
    }
}
