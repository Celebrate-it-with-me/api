<?php

namespace App\Http\Resources\AppResources;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'menuId' => $this->chosenMenu()?->id,
            'menuName' => $this->chosenMenu()?->title,
            'hasChosen' => $this->selectedMenuItems->isNotEmpty(),
            'starterName' => $this->getNameByMenuType('starter'),
            'mainName' => $this->getNameByMenuType('main'),
            'dessertName' => $this->getNameByMenuType('dessert'),
        ];
    }

    /** The menu of the dishes the guest chose (the one of her lowest dish id); null when she chose none. */
    private function chosenMenu(): ?Menu
    {
        return $this->selectedMenuItems->sortBy('id')->first()?->menu;
    }

    private function getNameByMenuType(string $type): string
    {
        if (! $this->selectedMenuItems) {
            return 'N/A';
        }

        $starter = $this->selectedMenuItems->where('type', $type)->first();
        if (! $starter) {
            return 'N/A';
        }

        return $starter->name;
    }
}
