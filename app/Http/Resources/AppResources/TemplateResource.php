<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\UserResource;
use App\Models\Guest;
use App\Models\MainGuest;
use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    private Guest $mainGuest;

    public function __construct($resource, string $guestCode)
    {
        parent::__construct($resource);
        $this->mainGuest = $this->initMainGuest($guestCode);
    }

    /**
     * Init Main Guest Data.
     * @param string $guestCode
     * @return MainGuest
     */
    private function initMainGuest(string $guestCode): Guest
    {
        return Guest::query()
            ->where('code', $guestCode)
            ->first();
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $companionQty = Guest::query()
            ->where('parent_id', $this->mainGuest->id)
            ->count();

        return [
            'event' => [
                'id' => $this->id,
                'eventName' => $this->event_name,
                'eventDescription' => $this->event_description,
                'eventDate' => $this->event_date,
                'organizer' => UserResource::make($this->organizer),
                'status' => $this->status,
                'customUrlSlug' => $this->custom_url_slug,
                'visibility' => $this->visibility,
                'createdAt' => $this->created_at->toDateTimeString(),
                'updatedAt' => $this->updated_at->toDateTimeString(),
                'selected' => false,
                'saveTheDate' => SaveTheDateResource::make($this->saveTheDate),
                'commentsConfig' => $this->getCommentsConfig(),
                'hasMenu' => $this->hasMenu(),
                'mainMenu' => $this->getEventMainMenu(),
                'eventFeature' => EventFeatureResource::make($this->eventFeature),
                'sweetMemoriesImages' => SweetMemoriesImageResource::collection($this->sweetMemoriesImages),
                'sweetMemoriesConfig' => SweetMemoriesConfigResource::make($this->sweetMemoriesConfig),
                'eventLocations' => EventLocationResource::collection($this->locations),
            ],
            'mainGuest' => [
                'id' => $this->mainGuest->id,
                'eventId' => $this->mainGuest->event_id,
                'name' => $this->mainGuest->name,
                'email' => $this->mainGuest->email,
                'phone' => $this->mainGuest->phone,
                'mealPreference' => $this->mainGuest->meal_preference,
                'accessCode' => $this->mainGuest->code,
                'rsvpStatus' => $this->mainGuest->rsvp_status,
                'rsvpStatusDate' => $this->mainGuest->rsvp_status_date
                    ? $this->mainGuest->rsvp_status_date->diffForHumans()
                    : null,
                'notes' => $this->mainGuest->notes,
                'tags' => $this->mainGuest->tags,
                'menuSelected' => $this->getGuestMenuWithItems(),
                'companionQty' => $companionQty,
                'companions' => GuestResource::collection($this->mainGuest->companions)
            ]
        ];
    }

    private function hasMenu(): bool
    {
        return $this->eventFeature->menu ?? false;
    }


    private function getEventMainMenu(): array
    {
        $menu = Menu::query()
            ->where('event_id', $this->id)
            ->where('is_default', true)
            ->first();

        if (!$menu) {
            return [];
        }

        $groupedItems = $menu->menuItems->groupBy('type')->map(function ($items) {
            return $items->values()->toArray();
        });

        return [
            'menu' => $menu->toArray(),
            'menuItems' => $groupedItems
        ];
    }

    private function getGuestMenuWithItems(): array
    {
        if (!$this->mainGuest->assigned_menu_id) {
            return [];
        }

        // Use the relationship defined in the Guest model
        $menu = $this->mainGuest->menuAssigned()->with('menuItems')->first();

        if (!$menu) {
            return [];
        }

        $groupedItems = $menu->menuItems->groupBy('type')->map(function ($items) {
            return $items->values()->toArray();
        });

        return [
            'menu' => $menu->toArray(),
            'menuItems' => $groupedItems
        ];
    }


    /**
     * Get comments config.
     */
    private function getCommentsConfig(): EventConfigCommentResource|array
    {
        if ($this->eventConfigComment) {
            return EventConfigCommentResource::make($this->eventConfigComment);
        }

        return [];
    }
}
