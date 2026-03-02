<?php

namespace App\Http\Services\AppServices\Themes;

use App\Models\Events;
use App\Models\Theme\EventTheme;
use App\Models\Theme\Theme;
use Illuminate\Support\Facades\DB;

class CreateEventThemeFromTemplateService
{
    public function handle(Events $event, Theme $theme): EventTheme
    {
        return DB::transaction(function () use ($event, $theme) {
            // Deactivate existing active themes
            EventTheme::query()
                ->where('event_id', $event->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);
            
            // Create event theme snapshot from template defaults
            $eventTheme = EventTheme::query()->create([
                'event_id' => $event->id,
                'theme_id' => $theme->id,
                'theme_config' => $theme->default_config ?? [],
                'is_active' => true,
            ]);
            
            return $eventTheme;
        });
    }
}
