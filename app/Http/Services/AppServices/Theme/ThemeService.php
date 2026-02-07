<?php

namespace App\Http\Services\AppServices\Theme;

use App\Models\Events;
use App\Models\EventTheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ThemeService
{
    /**
     * Retrieves the default configuration settings for the application.
     *
     * @return array The default configuration settings, including global colors, typography, and section details.
     */
    private function getDefaultConfig(): array
    {
        return [
            'global' => [
                'colors' => [
                    'primary' => '#e91e63',
                    'secondary' => '#7b2cbf',
                    'accent' => '#ffd700',
                    'background' => '#ffffff',
                    'text_primary' => '#333333',
                    'text_secondary' => '#666666'
                ],
                'typography' => [
                    'primary_font' => 'Inter',
                    'secondary_font' => 'Inter',
                    'heading_weight' => '600',
                    'body_weight' => '400'
                ]
            ],
            'sections' => []
        ];
    }

    /**
     * Retrieves the theme associated with the given event. If no theme exists, a default theme is created and returned.
     *
     * @param Events $event The event for which the theme is retrieved or created.
     * @return EventTheme The existing or newly created theme for the event.
     */
    public function getOrCreateTheme(Events $event): EventTheme
    {
        return $event->theme ?? $this->createDefaultTheme($event);
    }

    /**
     * Creates a default theme for the given event within a database transaction.
     *
     * @param Events $event The event for which the default theme is to be created.
     * @return EventTheme The newly created default theme associated with the event.
     * @throws Throwable
     */
    public function createDefaultTheme(Events $event): EventTheme
    {
        return DB::transaction(function () use ($event) {
            return EventTheme::create([
                'event_id' => $event->id,
                'theme_config' => $this->getDefaultConfig(),
                'is_active' => true
            ]);
        });
    }

    /**
     * Updates the theme configuration for a given event.
     *
     * This method validates the provided theme configuration, merges it with the
     * existing configuration, and updates the theme associated with the event
     * within a database transaction.
     *
     * @param Events $event The event for which the theme configuration is being updated.
     * @param array $themeConfig The new theme configuration data to be applied.
     * @return EventTheme The updated theme associated with the event.
     * @throws Throwable
     */
    public function updateThemeConfig(Events $event, array $themeConfig): EventTheme
    {
        $this->validateThemeConfig($themeConfig);

        return DB::transaction(function () use ($event, $themeConfig) {
            $theme = $this->getOrCreateTheme($event);

            $mergedConfig = $this->mergeThemeConfigs(
                $theme->theme_config,
                $themeConfig
            );

            $theme->update([
                'theme_config' => $mergedConfig
            ]);

            return $theme->fresh();
        });
    }

    /**
     * Reset the theme configuration of an event to its default settings.
     *
     * @param Events $event The event for which the theme configuration is to be reset.
     * @return EventTheme The updated theme associated with the event.
     * @throws Throwable If there is an error during the database transaction.
     */
    public function resetToDefault(Events $event): EventTheme
    {
        return DB::transaction(function () use ($event) {
            $theme = $this->getOrCreateTheme($event);

            $theme->update([
                'theme_config' => $this->getDefaultConfig()
            ]);

            return $theme->fresh();
        });
    }

    /**
     *
     * @param Events $event
     * @return void
     * @throws Throwable
     */
    public function deleteTheme(Events $event): void
    {
        DB::transaction(function () use ($event) {
            if ($event->theme) {
                $event->theme->delete();
            }
        });
    }

    /**
     * Resolve the theme configuration by applying global inheritance to section configurations.
     *
     * @param EventTheme $theme
     * @return array
     */
    public function getResolvedConfig(EventTheme $theme): array
    {
        return $this->resolveConfig($theme->theme_config);
    }

    /**
     * Resolve the theme configuration by applying global inheritance to section configurations.
     *
     * @param array $config The theme configuration to resolve.
     * @return array The resolved theme configuration.
     */
    public function resolveConfig(array $config): array
    {
        $global = $config['global'] ?? [];
        $sections = $config['sections'] ?? [];

        $resolvedSections = array_map(function ($sectionConfig) use ($global) {
            return $this->mergeSectionWithGlobal($global, $sectionConfig);
        }, $sections);

        return [
            'global' => $global,
            'sections' => $resolvedSections,
            'resolved_sections' => $resolvedSections // Para fácil acceso en frontend
        ];
    }

    /**
     * @param array $global
     * @param array $section
     * @return array
     */
    private function mergeSectionWithGlobal(array $global, array $section): array
    {
        $merged = [];

        $merged['colors'] = array_merge(
            $global['colors'] ?? [],
            $section['colors'] ?? []
        );

        $merged['typography'] = array_merge(
            $global['typography'] ?? [],
            $section['typography'] ?? []
        );

        foreach ($section as $key => $value) {
            if (!in_array($key, ['colors', 'typography'])) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Merge two theme configurations recursively, preserving structure.
     *
     * @param array $existing
     * @param array $new
     * @return array
     */
    private function mergeThemeConfigs(array $existing, array $new): array
    {
        $merged = $existing;

        if (isset($new['global'])) {
            $merged['global'] = array_merge_recursive(
                $merged['global'] ?? [],
                $new['global']
            );
        }

        if (isset($new['sections'])) {
            $merged['sections'] = array_merge_recursive(
                $merged['sections'] ?? [],
                $new['sections']
            );
        }

        return $merged;
    }

    /**
     * Validates the structure of the theme configuration.
     *
     * @param array $config The theme configuration to validate.
     * @return void
     */
    private function validateThemeConfig(array $config): void
    {
        if (!is_array($config)) {
            throw ValidationException::withMessages([
                'theme_config' => 'Theme config must be an array'
            ]);
        }

        if (isset($config['global']['colors'])) {
            foreach ($config['global']['colors'] as $colorKey => $colorValue) {
                if (!$this->isValidColor($colorValue)) {
                    throw ValidationException::withMessages([
                        "theme_config.global.colors.$colorKey" => 'Invalid color format'
                    ]);
                }
            }
        }

        if (isset($config['sections'])) {
            foreach ($config['sections'] as $sectionName => $sectionConfig) {
                if (isset($sectionConfig['colors'])) {
                    foreach ($sectionConfig['colors'] as $colorKey => $colorValue) {
                        if (!$this->isValidColor($colorValue)) {
                            throw ValidationException::withMessages([
                                "theme_config.sections.$sectionName.colors.$colorKey" => 'Invalid color format'
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Validates if a given color string is in a recognized format.
     *
     * This method checks whether the input string matches the format of
     * valid hex color codes or valid RGB/RGBA color definitions.
     *
     * @param string $color The color string to validate.
     * @return bool True if the color string is valid, false otherwise.
     */
    private function isValidColor(string $color): bool
    {
        // Hex colors
        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
            return true;
        }

        // RGB/RGBA colors
        if (preg_match('/^rgba?\([0-9,.\s]+\)$/', $color)) {
            return true;
        }

        return false;
    }
}
