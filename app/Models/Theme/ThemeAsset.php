<?php

namespace App\Models\Theme;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ThemeAsset extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'event_theme_id',
        'section',
        'asset_type',
        'provider',
        'disk',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'alt_text',
        'meta',
        'sort_order',
        'is_active',
    ];
    
    protected $casts = [
        'file_size' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];
    
    public function eventTheme(): BelongsTo
    {
        return $this->belongsTo(EventTheme::class);
    }
    
    public function getUrlAttribute(): string
    {
        $disk = $this->disk ?: config('filesystems.default');
        
        return Storage::disk($disk)->url($this->file_path);
    }
    
    public function getFullUrlAttribute(): string|UrlGenerator
    {
        return url($this->url);
    }
    
    public function scopeSection($query, $section): mixed
    {
        return $query->where('section', $section);
    }
    
    public function scopeAssetType($query, $type): mixed
    {
        return $query->where('asset_type', $type);
    }
    
    public function scopeActive($query): mixed
    {
        return $query->where('is_active', true);
    }
    
    public function isImage(): bool
    {
        return is_string($this->mime_type) && str_starts_with($this->mime_type, 'image/');
    }
    
    public function isVideo(): bool
    {
        return is_string($this->mime_type) && str_starts_with($this->mime_type, 'video/');
    }
}
