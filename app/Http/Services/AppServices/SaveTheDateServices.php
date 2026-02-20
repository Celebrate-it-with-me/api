<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SaveTheDate;

class SaveTheDateServices
{
    public function getForEvent(Events $event): ?SaveTheDate
    {
        return SaveTheDate::query()
            ->where('event_id', $event->id)
            ->first();
    }
    
    public function upsertForEvent(Events $event, array $payload): SaveTheDate
    {
        // Ensure event_id is always set from route context
        $payload['event_id'] = $event->id;
        
        return SaveTheDate::query()->updateOrCreate(
            ['event_id' => $event->id],
            $payload
        );
    }
    
    public function deleteForEvent(Events $event): bool
    {
        $model = $this->getForEvent($event);
        
        if (!$model) {
            return false;
        }
        
        return (bool) $model->delete();
    }
}
