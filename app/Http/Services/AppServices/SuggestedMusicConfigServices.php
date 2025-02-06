<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SuggestedMusicConfig;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

class SuggestedMusicConfigServices
{
    protected Request $request;
    protected SuggestedMusicConfig $suggestedMusicConfig;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->suggestedMusicConfig = new SuggestedMusicConfig();
    }
    
    /**
     * Get event suggested music Config.
     * @param Events $event
     * @return HasMany
     */
    public function getSuggestedMusicConfig(Events $event): HasMany
    {
        return $event->suggestedMusicConfig;
    }
    
    /**
     * Create a new suggested music configuration for the given event.
     *
     * @param Events $event
     * @return SuggestedMusicConfig
     */
    public function create(Events $event): SuggestedMusicConfig
    {
        return SuggestedMusicConfig::query()->create([
            'event_id' => $event->id,
            'title' => $this->request->input('title'),
            'sub_title' => $this->request->input('subTitle'),
            'main_color' => $this->request->input('mainColor'),
            'secondary_color' => $this->request->input('secondaryColor'),
            'use_preview' => $this->request->input('usePreview'),
            'use_vote_system' => $this->request->input('useVoteSystem'),
            'search_limit' => $this->request->input('searchLimit'),
        ]);
    }
    
    /**
     * Update the event's suggested music configuration.
     *
     * @param SuggestedMusicConfig $suggestedMusicConfig
     * @return SuggestedMusicConfig
     */
    public function update(SuggestedMusicConfig $suggestedMusicConfig): SuggestedMusicConfig
    {
        $this->suggestedMusicConfig = $suggestedMusicConfig;
        
        $this->suggestedMusicConfig->title = $this->request->input('title');
        $this->suggestedMusicConfig->sub_title = $this->request->input('subTitle');
        $this->suggestedMusicConfig->main_color = $this->request->input('mainColor');
        $this->suggestedMusicConfig->secondary_color = $this->request->input('secondaryColor');
        $this->suggestedMusicConfig->use_preview = $this->request->input('usePreview');
        $this->suggestedMusicConfig->use_vote_system = $this->request->input('useVoteSystem');
        $this->suggestedMusicConfig->search_limit = $this->request->input('searchLimit');
        $this->suggestedMusicConfig->save();
        
        $this->suggestedMusicConfig->refresh();
        return $this->suggestedMusicConfig;
    }
    
    /**
     * Delete the specified SuggestedMusicConfig instance and return the deleted instance.
     *
     * @param SuggestedMusicConfig $suggestedMusicConfig
     * @return SuggestedMusicConfig
     */
    public function destroy(SuggestedMusicConfig $suggestedMusicConfig): SuggestedMusicConfig
    {
        $tempSuggestedMusicConfig = clone $suggestedMusicConfig;
        $suggestedMusicConfig->delete();
        
        return $tempSuggestedMusicConfig;
    }
    
}
