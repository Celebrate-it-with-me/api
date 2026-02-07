<?php

namespace App\Http\Services\AppServices\Theme;

use App\Models\Events;
use App\Models\Guest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

class PublicEventService
{
    public function __construct(
        private ThemeService $themeService
    ) {}

    /**
     * Getting event data for guests.
     * @param int $eventId
     * @param string $guestToken
     * @return array
     */
    public function getEventDataForGuest(int $eventId, string $guestToken): array
    {
        [$event, $guest] = $this->validateEventAndGuest($eventId, $guestToken);

        return [
            'event' => $this->formatEventData($event),
            'guest' => $this->formatGuestData($guest),
            'theme' => $this->getEventThemeData($event)
        ];
    }

    /**
     * Getting event theme for guests.
     * @param int $eventId
     * @param string $guestToken
     * @return array[]
     */
    public function getEventThemeForGuest(int $eventId, string $guestToken): array
    {
        [$event, $guest] = $this->validateEventAndGuest($eventId, $guestToken);

        return [
            'theme' => $this->getEventThemeData($event)
        ];
    }

    /**
     * Submitting guest RSVP
     * @param int $eventId
     * @param string $guestToken
     * @param array $rsvpData
     * @return Guest
     */
    public function submitGuestRsvp(int $eventId, string $guestToken, array $rsvpData): Guest
    {
        [$event, $guest] = $this->validateEventAndGuest($eventId, $guestToken);

        $guest->update([
            'rsvp_status' => $rsvpData['status'],
            'rsvp_guests_count' => $rsvpData['guests_count'] ?? 1,
            'rsvp_notes' => $rsvpData['notes'] ?? null,
            'rsvp_dietary_restrictions' => $rsvpData['dietary_restrictions'] ?? null,
            'rsvp_submitted_at' => now()
        ]);

        return $guest->fresh();
    }

    /**
     * Getting section data for guest
     * @param int $eventId
     * @param string $guestToken
     * @param string $section
     * @return array
     */
    public function getEventSectionForGuest(int $eventId, string $guestToken, string $section): array
    {
        $this->validateSection($section);
        [$event, $guest] = $this->validateEventAndGuest($eventId, $guestToken);

        return [
            'section' => $section,
            'data' => $this->getSectionData($event, $section)
        ];
    }

    /**
     * Validating Event and token
     * @param int $eventId
     * @param string $guestToken
     * @return array
     */
    private function validateEventAndGuest(int $eventId, string $guestToken): array
    {
        $event = Events::findOrFail($eventId);

        $guest = Guest::where('token', $guestToken)
            ->where('event_id', $event->id)
            ->first();

        if (!$guest) {
            throw new ModelNotFoundException('Invalid guest token');
        }

        return [$event, $guest];
    }

    /**
     * Validating Sections
     * @param string $section
     * @return void
     */
    private function validateSection(string $section): void
    {
        $allowedSections = ['hero', 'details', 'location', 'rsvp', 'save_the_date', 'gallery'];

        if (!in_array($section, $allowedSections)) {
            throw new InvalidArgumentException('Invalid section');
        }
    }

    /**
     * Theme data.
     * @param Events $event
     * @return array
     */
    private function getEventThemeData(Events $event): array
    {
        $theme = $event->theme;

        if (!$theme) {
            return [
                'config' => $this->themeService->resolveConfig([]),
                'assets' => [],
                'has_custom_theme' => false
            ];
        }

        $resolvedConfig = $this->themeService->getResolvedConfig($theme);
        $assets = $this->formatThemeAssets($theme);

        return [
            'config' => $resolvedConfig,
            'assets' => $assets,
            'has_custom_theme' => true
        ];
    }

    /**
     * Getting theme assets
     * @param $theme
     * @return array
     */
    private function formatThemeAssets($theme): array
    {
        return $theme->activeAssets()
            ->get()
            ->groupBy('section')
            ->map(function ($sectionAssets) {
                return $sectionAssets->groupBy('asset_type')->map(function ($typeAssets) {
                    return $typeAssets->map(function ($asset) {
                        return [
                            'id' => $asset->id,
                            'url' => $asset->full_url,
                            'alt_text' => $asset->alt_text,
                            'file_name' => $asset->file_name
                        ];
                    });
                });
            });
    }

    /**
     * Formatting api data for the event.
     * @param Events $event
     * @return array
     */
    private function formatEventData(Events $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->eventName,
            'description' => $event->eventDescription,
            'date' => $event->eventDate,
            'start_time' => $event->startTime,
            'end_time' => $event->endTime,
            'location' => $this->formatLocationData($event),
            'dress_code' => $event->dressCode,
            'additional_info' => $event->additionalInfo,
            'is_public' => $event->isPublic ?? false,
            'created_at' => $event->created_at->toISOString()
        ];
    }

    /**
     * Formatting location data.
     * @param Events $event
     * @return array|null
     */
    private function formatLocationData(Events $event): ?array
    {
        if (!$event->location) {
            return null;
        }

        return [
            'name' => $event->location->name,
            'address' => $event->location->address,
            'city' => $event->location->city,
            'state' => $event->location->state,
            'coordinates' => [
                'lat' => $event->location->latitude,
                'lng' => $event->location->longitude
            ]
        ];
    }

    /**
     * Formatting guest data
     * @param Guest $guest
     * @return array
     */
    private function formatGuestData(Guest $guest): array
    {
        return [
            'id' => $guest->id,
            'name' => $guest->name,
            'email' => $guest->email,
            'token' => $guest->token,
            'rsvp_status' => $guest->rsvp_status,
            'rsvp_guests_count' => $guest->rsvp_guests_count,
            'rsvp_notes' => $guest->rsvp_notes,
            'rsvp_dietary_restrictions' => $guest->rsvp_dietary_restrictions,
            'rsvp_submitted_at' => $guest->rsvp_submitted_at?->toISOString(),
            'can_bring_guests' => $guest->can_bring_guests ?? true,
            'max_guests' => $guest->max_guests ?? 1
        ];
    }

    /**
     * Getting section data.
     * @param Events $event
     * @param string $section
     * @return array|string[]
     */
    private function getSectionData(Events $event, string $section): array
    {
        $baseData = match($section) {
            'details' => [
                'name' => $event->eventName,
                'description' => $event->eventDescription,
                'date' => $event->eventDate,
                'time' => [
                    'start' => $event->startTime,
                    'end' => $event->endTime
                ],
                'dress_code' => $event->dressCode,
                'additional_info' => $event->additionalInfo
            ],

            'location' => [
                'name' => $event->location?->name,
                'address' => $event->location?->address,
                'city' => $event->location?->city,
                'state' => $event->location?->state,
                'zip_code' => $event->location?->zip_code,
                'coordinates' => [
                    'lat' => $event->location?->latitude,
                    'lng' => $event->location?->longitude
                ]
            ],

            default => [
                'section_name' => $section
            ]
        };

        if ($event->theme) {
            $baseData['theme_config'] = $event->theme->getSectionConfig($section);
        }

        return $baseData;
    }
}
