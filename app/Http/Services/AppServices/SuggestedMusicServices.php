<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SaveTheDate;
use App\Models\SuggestedMusic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class SuggestedMusicServices
{
    protected Request $request;
    protected SuggestedMusic $suggestedMusic;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->suggestedMusic = new SuggestedMusic();
    }
    
    /**
     * Get event save the date.
     * @param Events $event
     * @return mixed
     */
    public function getSuggestedMusic(Events $event): mixed
    {
        return $event->musicSuggestions;
    }
    
    /**
     * Create STD event.
     * @param Events $event
     * @return SaveTheDate|Model
     */
    public function create(Events $event): Model|SuggestedMusic
    {
        return SuggestedMusic::query()->create([
            'event_id' => $event->id,
            'name' => $this->request->get('name'),
            'platform'  => $this->getPlatform(),
            'platform_url' => $this->request->get('platform_url'),
            'suggested_by' => $this->request->get('suggested_by'),
        ]);
    }
    
    /**
     * Determine the platform based on the provided platform URL.
     *
     * @return string
     */
    private function getPlatform(): string
    {
        return match (true) {
            str_contains($this->request->get('platform_url'), 'youtube.com') || str_contains(
                $this->request->get('platform_url'),
                'youtu.be'
            ) => 'youtube',
            str_contains($this->request->get('platform_url'), 'spotify.com') => 'spotify',
            default => 'unknown',
        };
    }
    
    /**
     * Remove the provided SuggestedMusic instance and return its clone.
     *
     * @param SuggestedMusic $suggestedMusic
     * @return SuggestedMusic
     */
    public function remove(SuggestedMusic $suggestedMusic): SuggestedMusic
    {
        $suggestedMusicClone = clone $suggestedMusic;
        $suggestedMusic->delete();
        
        return $suggestedMusicClone;
    }
}
