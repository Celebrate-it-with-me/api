<?php

namespace App\Models\Theme;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Theme extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'tier',
        'is_published',
        'default_config',
    ];
    
    protected $casts = [
        'is_published' => 'boolean',
        'default_config' => 'array',
    ];
    
    public function eventThemes(): HasMany
    {
        return $this->hasMany(EventTheme::class);
    }
}
