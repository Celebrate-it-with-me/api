<?php

namespace App\Http\Services\AppServices;

use App\Models\BackgroundMusic;
use App\Models\Events;
use Illuminate\Http\Request;

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
}
