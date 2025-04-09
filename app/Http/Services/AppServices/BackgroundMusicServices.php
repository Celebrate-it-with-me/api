<?php

namespace App\Http\Services\AppServices;

use App\Models\BackgroundMusic;
use App\Models\Events;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BackgroundMusicServices
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    /**
     * Create a new background music configuration for the given event.
     *
     * @param Events $event
     * @return BackgroundMusic
     */
    public function create(Events $event): BackgroundMusic
    {
        $songPath = null;
        if ($this->request->hasFile('songFile') && $this->request->file('songFile')->isValid()) {
            $songPath = $this->request->file('songFile')
                ->store("sound/background-music/$event->id", 'public');
        }
        
        // Create the Background music entry in the database
        return BackgroundMusic::query()->create([
            'event_id' => $event->id,
            'icon_size' => $this->request->input('iconSize'),
            'icon_position' => $this->request->input('iconPosition'),
            'icon_color' => $this->request->input('iconColor'),
            'auto_play' => $this->request->input('autoplay') ? 1 : 0,
            'song_url' => $songPath,
        ]);
    }
    
    /**
     * Update an existing background music configuration.
     *
     * @param BackgroundMusic $backgroundMusic
     * @return BackgroundMusic
     */
    public function update(BackgroundMusic $backgroundMusic): BackgroundMusic
    {
        $songPath = $backgroundMusic->song_url;
        if ($this->request->hasFile('songFile') && $this->request->file('songFile')->isValid()) {
            
            // Delete existing image file if it exists
            if ($songPath && Storage::disk('public')->exists($songPath)) {
                Storage::disk('public')->delete($songPath);
            }
            
            $songPath = $this->request->file('songFile')
                ->store("sound/background-music/$backgroundMusic->event_id", 'public');
        }
        
        $backgroundMusic->icon_size = $this->request->input('iconSize');
        $backgroundMusic->icon_position = $this->request->input('iconPosition');
        $backgroundMusic->icon_color = $this->request->input('iconColor');
        $backgroundMusic->auto_play = $this->request->input('autoplay') ? 1 : 0;
        $backgroundMusic->song_url = $songPath;
        
        \Log::info('checking new background music', [$backgroundMusic, $this->request->all()]);
        
        $backgroundMusic->save();
        
        return $backgroundMusic;
        
        
        
    }
}
