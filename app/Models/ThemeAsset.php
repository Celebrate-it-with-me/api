<?php

namespace App\Models;

use Database\Factories\ThemeAssetFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ThemeAsset extends Model
{
    /** @use HasFactory<ThemeAssetFactory> */
    use HasFactory;

    protected $fillable = [
        'event_theme_id',
        'section',
        'asset_type',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'alt_text',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Establishes a relationship indicating that this model belongs to an EventTheme.
     */
    public function eventTheme(): BelongsTo
    {
        return $this->belongsTo(EventTheme::class);
    }

    /**
     * Retrieves the URL for the file path associated with the model.
     *
     * @return string The generated URL for the file path.
     */
    public function getUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }

    /**
     * Retrieves the full URL for the file path associated with the model.
     *
     * @return string|UrlGenerator The generated full URL for the file path.
     */
    public function getFullUrlAttribute(): string|UrlGenerator
    {
        return url(Storage::url($this->file_path));
    }

    /**
     * Scope by section
     */
    public function scopeSection($query, $section): mixed
    {
        return $query->where('section', $section);
    }

    /**
     * Scope by asset type
     */
    public function scopeAssetType($query, $type): mixed
    {
        return $query->where('asset_type', $type);
    }

    /**
     * Scope for active assets
     */
    public function scopeActive($query): mixed
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if the asset is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Determines if the current mime type represents a video.
     *
     * @return bool True if the mime type starts with 'video/', false otherwise.
     */
    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }
}
