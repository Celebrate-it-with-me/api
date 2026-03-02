<?php

namespace App\Models\Theme;

use App\Models\Events;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTheme extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'event_id',
        'theme_id',
        'theme_config',
        'theme_version',
        'is_active',
    ];
    
    protected $casts = [
        'theme_config' => 'array',
        'theme_version' => 'integer',
        'is_active' => 'boolean',
    ];
    
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }
    
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
    
    public function assets(): HasMany
    {
        return $this->hasMany(ThemeAsset::class);
    }
    
    public function activeAssets(): HasMany
    {
        return $this->hasMany(ThemeAsset::class)->where('is_active', true);
    }
    
    public function getAssetsBySection(string $section): Collection
    {
        return $this->assets()->where('section', $section)->get();
    }
    
    public function getAssets(string $section, string $assetType): Collection
    {
        return $this->assets()
            ->where('section', $section)
            ->where('asset_type', $assetType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
    
    public function getSectionConfig(string $section): array
    {
        // NOTE: your JSON shape uses 'section'. Keep it for now to avoid breaking existing data.
        // Later we can migrate to 'sections' with a data migration.
        return $this->theme_config['section'][$section] ?? [];
    }
    
    public function getGlobalConfig(): array
    {
        return $this->theme_config['global'] ?? [];
    }
}
