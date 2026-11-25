<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\UserResource;
use App\Http\Services\AppServices\EventModules;
use App\Http\Services\AppServices\GuestLimit;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            // The same moments as unambiguous ISO 8601 with the event zone's offset (startDate/endDate stay for compatibility).
            'startsAt' => $this->isoInEventZone($this->start_date),
            'endsAt' => $this->isoInEventZone($this->end_date),
            'locale' => $this->locale,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'organizer' => $this->getOwner(),
            'userRole' => $this->getUserRole($request->user()),
            'status' => $this->status,
            'invitationStage' => $this->invitation_stage,
            'customUrlSlug' => $this->custom_url_slug,
            // The address to share: the public invitation page of the event (it asks each guest for the code); null without a slug.
            'publicUrl' => $this->publicUrl(),

            'publishedAt' => $this->published_at?->toIso8601String(),
            // The UI locks the address field and explains why: links may already be out.
            'slugLocked' => $this->isSlugLocked(),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
            'selected' => false,
            // What the menu shows: from the module catalog, by the event's plan (see EventModules).
            'modules' => app(EventModules::class)->forEvent($this->resource),
            'guestsCount' => $this->getEventGuestsCount(),
            // The plan's guest limit (people, with companions), or null when it has none.
            'guestLimit' => app(GuestLimit::class)->limitFor($this->resource),
            'rsvpResponse' => $this->rsvpResponse(),
            // How much of the event is configured (not how many guests answered) and which modules are still missing.
            'setupProgress' => $this->calculateSetupProgress(),
            'setupProgressKind' => 'configuration',
            'setupPending' => $this->pendingSetupModules(),
        ];
    }

    /** Dates are stored as wall-clock time of the event zone: read them as that and write the zone's offset. */
    private function isoInEventZone(?CarbonInterface $date): ?string
    {
        if (! $date) {
            return null;
        }

        return Carbon::parse($date->format('Y-m-d H:i:s'), $this->timezone ?: 'America/New_York')->toIso8601String();
    }

    private function publicUrl(): ?string
    {
        $base = rtrim((string) config('app.frontend_app.url'), '/');

        return $this->custom_url_slug && $base !== '' ? $base . '/i/' . rawurlencode($this->custom_url_slug) : null;
    }

    private function getEventGuestsCount(): int
    {
        return $this->resource->guestsTotal();
    }

    private function rsvpResponse()
    {
        return $this->resource->guestsResponded();
    }

    private function getUserRole($user): ?string
    {
        if (! $user || ! $this->userRoles) {
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
}
