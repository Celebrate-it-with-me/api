<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SaveTheDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SaveTheDateServices
{
    protected Request $request;
    protected SaveTheDate $saveTheDate;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->saveTheDate = new SaveTheDate();
    }
    
    /**
     * Get event save the date.
     * @param Events $event
     * @return mixed
     */
    public function getEventSTD(Events $event): mixed
    {
        return $event->saveTheDate;
    }
    
    /**
     * Create STD event.
     * @param Events $event
     * @return SaveTheDate|Model
     */
    public function createEventSTD(Events $event): Model|SaveTheDate
    {
        $imagePath = null;
        
        if ($this->request->hasFile('image') && $this->request->file('image')->isValid()) {
            $imagePath = $this->request->file('image')
                ->store("images/save-the-date/$event->id", 'public');
        }
        
        return SaveTheDate::query()->create([
            'event_id' => $event->id,
            'std_title' => $this->request->input('stdTitle'),
            'std_subtitle' => $this->request->input('stdSubtitle'),
            'image_url' => $imagePath,
            'background_color' => $this->request->input('backgroundColor'),
            'use_countdown' => $this->request->input('useCountdown') === "true",
            'use_add_to_calendar' => $this->request->input('useAddToCalendar') === "true",
            'is_enabled' => $this->request->input('isEnabled') === "true",
        ]);
    }
    
    /**
     * Updates the SaveTheDate event with new data, including handling image uploads.
     *
     * @param SaveTheDate $saveTheDate The SaveTheDate model instance to be updated.
     * @return Model|SaveTheDate The updated SaveTheDate model instance.
     */
    public function updateEventSTD(SaveTheDate $saveTheDate): Model|SaveTheDate
    {
        $imagePath = $saveTheDate->image_url;
        
        // Handle new image upload from the request
        if ($this->request->hasFile('image') && $this->request->file('image')->isValid()) {
            // Delete existing image file if it exists
            if ($imagePath && Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }
            
            $imagePath = $this->request->file('image')
                ->store("images/save-the-date/$saveTheDate->event_id", 'public');
        }
        
        // Update existing SaveTheDate record with new data
        $saveTheDate->update([
            'std_title' => $this->request->input('stdTitle'),
            'std_subtitle' => $this->request->input('stdSubtitle'),
            'image_url' => $imagePath,
            'background_color' => $this->request->input('backgroundColor'),
            'use_countdown' => $this->request->input('useCountdown') === "true",
            'use_add_to_calendar' => $this->request->input('useAddToCalendar') === "true",
            'is_enabled' => $this->request->input('isEnabled') === "true",
        ]);
        
        return $saveTheDate;
    }
}
