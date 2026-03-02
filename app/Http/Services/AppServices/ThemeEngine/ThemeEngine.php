<?php

namespace App\Http\Services\AppServices\ThemeEngine;

use App\Models\Events;

class ThemeEngine
{
    use ResolvesThemeAssetsTrait;
    
    public function resolveForEvent(Events $event): array
    {
        $eventTheme = $event->activeEventTheme;
        if (!$eventTheme || !$eventTheme->theme) {
            return [
                'event' => $event,
                'theme' => null,
                'config' => ['global' => [], 'section' => []],
                'assets' => [],
            ];
        }
        
        $theme = $eventTheme->theme;
        
        $defaultConfig = $theme->default_config ?? [];
        $eventConfig = $eventTheme->theme_config ?? [];
        
        // Merge with precedence to event overrides
        $config = $this->mergeConfig($defaultConfig, $eventConfig);
        
        // Assets fallback per section + type
        $assets = $this->resolveAssets($eventTheme->id, $theme->id);
        
        return [
            'event' => $event,
            'theme' => $theme,
            'config' => $config,
            'assets' => $assets,
        ];
    }
    
    private function mergeConfig(array $defaultConfig, array $eventConfig): array
    {
        // Deep merge where eventConfig wins
        // array_replace_recursive is acceptable here if your schema is associative
        return array_replace_recursive($defaultConfig, $eventConfig);
    }
}
