<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTheme extends Model
{
    /** @use HasFactory<\Database\Factories\EventThemeFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'theme_config',
        'is_active'
    ];

    protected $casts = [
        'theme_config' => 'array',
        'is_active' => 'boolean'
    ];

    /**
     * Defines a relationship indicating that this model belongs to the Events model.
     *
     * @return BelongsTo The relationship instance linking this model to Events.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }

    /**
     * Defines a relationship indicating that this model has many ThemeAsset models.
     *
     * @return HasMany The relationship instance linking this model to ThemeAsset models.
     */
    public function assets(): HasMany
    {
        return $this->hasMany(ThemeAsset::class);
    }

    /**
     * Defines a relationship indicating that this model has many active ThemeAsset models.
     * The related ThemeAsset models are filtered to include only those marked as active.
     *
     * @return HasMany The relationship instance linking this model to active ThemeAsset models.
     */
    public function activeAssets(): HasMany
    {
        return $this->hasMany(ThemeAsset::class)
            ->where('is_active', true);
    }

    /**
     * Retrieves a collection of assets filtered by the specified section.
     *
     * @param string $section The section to filter the assets by.
     * @return Collection A collection of assets belonging to the given section.
     */
    public function getAssetsBySection(string $section): Collection
    {
        return $this->assets()->where('section', $section)->get();
    }

    /**
     * Retrieves a collection of active assets filtered by the specified section and asset type.
     *
     * @param string $section The section to filter the assets by.
     * @param string $assetType The type of asset to filter by.
     * @return Collection A collection of active assets matching the given section and asset type.
     */
    public function getAssets(string $section, string $assetType): Collection
    {
        return $this->assets()
            ->where('section', $section)
            ->where('asset_type', $assetType)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Retrieves the configuration array for a specific section.
     *
     * @param string $section The section key to retrieve the configuration for.
     * @return array The configuration array for the provided section, or an empty array if not defined.
     */
    public function getSectionConfig(string $section): array
    {
        return $this->theme_config['section'][$section] ?? [];
    }

    /**
     * Retrieves the global configuration settings.
     *
     * @return array An associative array containing the global configuration settings.
     */
    public function getGlobalConfig(): array
    {
        return $this->theme_config['global'] ?? [];
    }

}
