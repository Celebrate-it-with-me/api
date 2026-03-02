<?php

namespace App\Http\Services\AppServices\ThemeEngine;

use App\Models\Theme\ThemeAsset;
use App\Models\Theme\ThemeBaseAsset;
use Illuminate\Support\Collection;

trait ResolvesThemeAssetsTrait
{
    private function resolveAssets(int $eventThemeId, int $themeId)
    {
        $overrides = ThemeAsset::query()
            ->where('event_theme_id', $eventThemeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        
        $base = ThemeBaseAsset::query()
            ->where('theme_id', $themeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        // Group both the same way: section -> asset_type -> list
        $overridesGrouped = $this->groupAssets($overrides);
        $baseGrouped = $this->groupAssets($base);
        
        // Merge: overrides win for each section/type if not empty
        $result = $baseGrouped;
        
        foreach ($overridesGrouped as $section => $types) {
            foreach ($types as $assetType => $items) {
                if (!empty($items)) {
                    $result[$section][$assetType] = $items;
                }
            }
        }
        
        return $result;
    }
    
    private function groupAssets(Collection $assets): array
    {
        $grouped = [];
        
        foreach ($assets as $asset) {
            $section = $asset->section;
            $type = $asset->asset_type;
            
            $grouped[$section][$type] ??= [];
            $grouped[$section][$type][] = [
                'id' => $asset->id,
                'section' => $section,
                'asset_type' => $type,
                'provider' => $asset->provider ?? 'upload',
                'mime_type' => $asset->mime_type,
                'file_name' => $asset->file_name,
                'file_size' => $asset->file_size,
                'alt_text' => $asset->alt_text ?? null,
                'sort_order' => $asset->sort_order ?? 0,
                'meta' => $asset->meta ?? null,
                'url' => $asset->url,
                'full_url' => $asset->full_url,
            ];
        }
        
        return $grouped;
    }
}
