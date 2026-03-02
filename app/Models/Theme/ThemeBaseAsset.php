<?php

namespace App\Models\Theme;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeBaseAsset extends Model
{
    
    protected $fillable = [
        'theme_id',
        'section',
        'asset_type',
        'provider',
        'disk',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'meta',
        'sort_order',
        'is_active'
    ];
    
    protected $casts = [
        'meta' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
    
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}
