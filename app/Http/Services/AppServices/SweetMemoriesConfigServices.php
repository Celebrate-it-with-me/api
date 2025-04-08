<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SuggestedMusicConfig;
use App\Models\SweetMemoriesConfig;
use Illuminate\Http\Request;

class SweetMemoriesConfigServices
{
    protected Request $request;
    protected SweetMemoriesConfig $sweetMemoriesConfig;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->sweetMemoriesConfig = new SweetMemoriesConfig();
    }
   
    
    /**
     * Create a new sweet configuration for the given event.
     *
     * @param Events $event
     * @return SweetMemoriesConfig
     */
    public function create(Events $event): SweetMemoriesConfig
    {
        return SweetMemoriesConfig::query()->create([
            'event_id' => $event->id,
            'title' => $this->request->input('title'),
            'sub_title' => $this->request->input('subTitle'),
            'background_color' => $this->request->input('backgroundColor'),
            'max_pictures' => $this->request->input('maxPictures'),
        ]);
    }
    
    /**
     * Update the given sweet memories configuration.
     * @param SweetMemoriesConfig $sweetMemoriesConfig
     * @return SweetMemoriesConfig
     */
    public function update(SweetMemoriesConfig $sweetMemoriesConfig): SweetMemoriesConfig
    {
        $sweetMemoriesConfig->title = $this->request->input('title');
        $sweetMemoriesConfig->sub_title = $this->request->input('subTitle');
        $sweetMemoriesConfig->background_color = $this->request->input('backgroundColor');
        $sweetMemoriesConfig->max_pictures = $this->request->input('maxPictures');
        
        $sweetMemoriesConfig->save();
        
        return $sweetMemoriesConfig;
    }
    
    /**
     * Destroy sweet memories configuration.
     * @param SweetMemoriesConfig $sweetMemoriesConfig
     * @return SweetMemoriesConfig
     */
    public function destroy(SweetMemoriesConfig $sweetMemoriesConfig): SweetMemoriesConfig
    {
        $sweetMemoriesConfig->delete();
        
        return $sweetMemoriesConfig;
    }
    
    /**
     * Retrieve the sweet memories configuration associated with the given event.
     *
     * @param Events $event
     * @return mixed
     */
    public function getSweetMemoriesConfig(Events $event): mixed
    {
        return $event->sweetMemoriesConfig()->first();
    }
    
}
