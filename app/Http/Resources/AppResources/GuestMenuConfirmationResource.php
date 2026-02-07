<?php

namespace App\Http\Resources\AppResources;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class GuestMenuConfirmationResource extends JsonResource
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
            'eventId' => $this->event_id,
            'parentId' => $this->parent_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'rsvpStatus' => $this->rsvp_status,
            'starterName' => $this->getNameByMenuType('starter'),
            'mainName' => $this->getNameByMenuType('main'),
            'dessertName' => $this->getNameByMenuType('dessert'),
        ];
    }
    
    
    private function getNameByMenuType(string $type): string
    {
        if (!$this->selectedMenuItems) {
            return 'N/A';
        }
        
        $starter = $this->selectedMenuItems->where('type', $type)->first();
        if (!$starter) {
            return 'N/A';
        }
        
        return $starter->name;
    }
}
