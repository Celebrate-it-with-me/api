<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'eventName' => $this->event_name,
            'eventDescription' => $this->event_description,
            'eventType' => $this->event_type_id,
            'startDate' => $this->start_date?->format('m/d/Y H:i'),
            'endDate' => $this->end_date?->format('m/d/Y H:i'),
            'organizer' => $this->getOwner(),
            'userRole' => $this->getUserRole($request->user()),
            'status' => $this->status,
            'customUrlSlug' => $this->custom_url_slug,
            'visibility' => $this->visibility,
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
            'selected' => false,
            'eventFeatures' => $this->transformFeatures()
        ];
    }
    
    private function getUserRole($user): ?string
    {
        if (!$user || !$this->userRoles) {
            return null;
        }
        
        $role = $this->userRoles->firstWhere('user_id', $user->id)?->role;
        
        return $role?->name;
    }
    
    private function getOwner(): UserResource
    {
        return UserResource::make(
            optional($this->userRoles->firstWhere(fn ($r) => $r->role?->name === 'owner'))->user
        );
    }
    
    /**
     * Transforms event features into an array with name and activation status for each feature.
     * Iterates through the provided eventFeature property and structures its data for further processing.
     *
     * @return array The transformed array containing event feature names and their activation status.
     */
    private function transformFeatures(): array
    {
        {
            if (!$this->eventFeature) {
                return [];
            }
            
            $eventFeatures = [];
            $eventFeaturesArray = $this->eventFeature->toArray();
            unset($eventFeaturesArray['id']);
            unset($eventFeaturesArray['event_id']);
            unset($eventFeaturesArray['created_at']);
            unset($eventFeaturesArray['updated_at']);
            
            foreach ($eventFeaturesArray as $key => $eventFeature) {
                $eventFeatures[] = [
                    'name' => Str::camel($key),
                    'isActive' => $eventFeature,
                ];
            }
            
            return $eventFeatures;
        }
    }
}
