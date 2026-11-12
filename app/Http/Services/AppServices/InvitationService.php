<?php

namespace App\Http\Services\AppServices;

use App\Models\DressCode;
use App\Models\EventLocation;
use App\Models\Events;
use App\Models\Guest;
use App\Models\GuestRsvpLog;
use App\Models\InvitationTemplate;
use App\Models\Rsvp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Builds the public invitation: event data + ordered sections + theme, resolved
 * for one guest. The guest code is the only credential, so everything returned
 * here is limited to what that guest may see (never other guests, never contact data).
 */
class InvitationService
{
    public const LOCALES = ['es', 'en'];

    public const STATUS_ATTENDING = 'attending';

    public const STATUS_NOT_ATTENDING = 'not-attending';

    public const STATUS_PENDING = 'pending';

    /** Section types the renderer understands in this release. */
    private const SECTION_TYPES = ['cover', 'locations', 'dress_code', 'rsvp', 'closing'];

    /** Only published events have a public invitation. */
    public function findEvent(string $slug): ?Events
    {
        return Events::query()
            ->where('custom_url_slug', $slug)
            ->where('status', 'published')
            ->first();
    }

    /** Invitations belong to main guests; a companion code does not open one. */
    public function findGuest(Events $event, string $code): ?Guest
    {
        return Guest::query()
            ->where('event_id', $event->id)
            ->where('code', $code)
            ->whereNull('parent_id')
            ->first();
    }

    public function build(Events $event, Guest $guest, ?string $lang = null): array
    {
        $locale = $this->resolveLocale($lang, $guest, $event);
        $template = $this->templateFor($event);

        return [
            'event' => [
                'name' => $event->event_name,
                'typeSlug' => $event->eventType?->slug,
                // Wall-clock time in the event timezone: render it without converting.
                'startsAt' => $event->start_date->format('Y-m-d\TH:i:s'),
                'timezone' => $event->timezone,
            ],
            'guest' => ['name' => $guest->name],
            'locale' => $locale,
            'theme' => $this->resolveTheme($event, $template),
            'sections' => $this->resolveSections($event, $guest, $template, $locale),
            // Free-plan watermark. Premium removes it once the plan model exists.
            'branding' => ['watermark' => true],
        ];
    }

    /**
     * Save the guest's answer for themselves and their companions.
     *
     * @param  array{guest: array{rsvpStatus: string}, companions?: array<int, array{id: int, rsvpStatus: string}>}  $payload
     * @return array<string, mixed> The refreshed rsvp section data
     *
     * @throws ValidationException
     */
    public function saveRsvp(Events $event, Guest $guest, array $payload): array
    {
        $window = $this->rsvpWindow($event);

        if (! $window['isOpen']) {
            throw ValidationException::withMessages(['rsvp' => 'closed']);
        }

        $companions = $guest->companions()->get()->keyBy('id');
        $requested = collect($payload['companions'] ?? [])->keyBy('id');

        // A guest can only answer for their own companions.
        if ($requested->keys()->diff($companions->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['companions' => 'invalid']);
        }

        $mainStatus = $payload['guest']['rsvpStatus'];

        DB::transaction(function () use ($guest, $companions, $requested, $mainStatus) {
            $this->setStatus($guest, $mainStatus);

            foreach ($companions as $id => $companion) {
                // Nobody attends under an invitation the main guest declined.
                $status = $mainStatus === self::STATUS_NOT_ATTENDING
                    ? self::STATUS_NOT_ATTENDING
                    : ($requested[$id]['rsvpStatus'] ?? null);

                if ($status !== null) {
                    $this->setStatus($companion, $status);
                }
            }
        });

        Cache::forget("event.{$event->id}.rsvp.stats");

        return $this->rsvpData($event, $guest->fresh());
    }

    private function setStatus(Guest $guest, string $status): void
    {
        if ($guest->rsvp_status === $status) {
            return;
        }

        $guest->forceFill(['rsvp_status' => $status, 'rsvp_status_date' => now()])->save();

        GuestRsvpLog::create([
            'guest_id' => $guest->id,
            'status' => $status,
            'changed_by' => null,
            'notes' => 'Guest RSVP via public invitation',
            'changed_at' => now(),
        ]);
    }

    private function resolveLocale(?string $lang, Guest $guest, Events $event): string
    {
        foreach ([$lang, $guest->locale, $event->locale] as $candidate) {
            if (in_array($candidate, self::LOCALES, true)) {
                return $candidate;
            }
        }

        return 'es';
    }

    private function templateFor(Events $event): InvitationTemplate
    {
        if ($event->template && $event->template->is_active) {
            return $event->template;
        }

        $free = InvitationTemplate::query()
            ->where('is_active', true)
            ->where('tier', InvitationTemplate::TIER_FREE);

        return (clone $free)->where('event_type_id', $event->event_type_id)->first()
            ?? (clone $free)->whereNull('event_type_id')->first()
            ?? $free->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function resolveTheme(Events $event, InvitationTemplate $template): array
    {
        $defaults = $template->theme_defaults;
        $override = $event->template_config['theme'] ?? [];

        $presets = $defaults['presets'] ?? [];
        $key = $override['preset'] ?? $defaults['preset'] ?? array_key_first($presets);
        $colors = $presets[$key]['colors'] ?? ($presets[array_key_first($presets)]['colors'] ?? []);

        return [
            'name' => $defaults['theme'] ?? 'classic',
            'preset' => $key,
            'colors' => array_merge($colors, $override['colors'] ?? []),
            'fonts' => array_merge($defaults['fonts'] ?? [], $override['fonts'] ?? []),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveSections(Events $event, Guest $guest, InvitationTemplate $template, string $locale): array
    {
        $base = collect($template->default_sections)->keyBy('type');
        $overrides = collect($event->template_config['sections'] ?? [])->keyBy('type');

        return $base->keys()->merge($overrides->keys())->unique()
            ->filter(fn (string $type) => in_array($type, self::SECTION_TYPES, true))
            ->map(fn (string $type) => array_merge($base[$type] ?? ['type' => $type, 'enabled' => false, 'order' => 99], $overrides[$type] ?? []))
            ->filter(fn (array $section) => $section['enabled'] ?? false)
            ->sortBy(fn (array $section) => $section['order'] ?? 99)
            ->map(function (array $section) use ($event, $guest, $locale) {
                $data = $this->sectionData($section['type'], $event, $guest, $section);

                // A section with nothing to show (no venue yet, no dress code) is left out.
                if ($data === null) {
                    return null;
                }

                return [
                    'type' => $section['type'],
                    'title' => $this->localized($section['title'] ?? null, $locale),
                    'text' => $this->localized($section['text'] ?? null, $locale),
                    // Always a JSON object, even for sections without data.
                    'data' => $data ?: (object) [],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @param string|array<string, ?string>|null $value */
    private function localized(string|array|null $value, string $locale): ?string
    {
        if (is_array($value)) {
            return $value[$locale] ?? $value['es'] ?? null;
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function sectionData(string $type, Events $event, Guest $guest, array $section): ?array
    {
        return match ($type) {
            'cover' => [
                'eventName' => $event->event_name,
                'description' => $event->event_description,
                'image' => $section['settings']['image'] ?? null,
            ],
            'locations' => $this->locationsData($event),
            'dress_code' => $this->dressCodeData($event),
            'rsvp' => $this->rsvpData($event, $guest),
            'closing' => [],
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function locationsData(Events $event): ?array
    {
        $locations = EventLocation::query()
            ->where('event_id', $event->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        if ($locations->isEmpty()) {
            return null;
        }

        return [
            'items' => $locations->map(fn (EventLocation $location) => [
                'name' => $location->name,
                'address' => $location->address,
                'city' => $location->city,
                'state' => $location->state,
                'zipCode' => $location->zip_code,
                'country' => $location->country,
                'notes' => $location->notes,
                'latitude' => $location->latitude !== null ? (float) $location->latitude : null,
                'longitude' => $location->longitude !== null ? (float) $location->longitude : null,
                'mapsUrl' => $this->mapsUrl($location),
            ])->all(),
        ];
    }

    private function mapsUrl(EventLocation $location): string
    {
        $query = ($location->latitude !== null && $location->longitude !== null)
            ? "{$location->latitude},{$location->longitude}"
            : implode(', ', array_filter([$location->name, $location->address, $location->city, $location->state]));

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }

    /** @return array<string, mixed>|null */
    private function dressCodeData(Events $event): ?array
    {
        $dressCode = DressCode::query()->where('event_id', $event->id)->first();

        if (! $dressCode) {
            return null;
        }

        return [
            'type' => $dressCode->dress_code_type,
            'description' => $dressCode->description,
            'reservedColors' => array_values($dressCode->reserved_colors ?? []),
            'suggestedColors' => array_values($dressCode->suggested_colors ?? []),
            // Uploads come with their URL; bank picks are shown by the app from its own photo bank.
            'images' => $dressCode->dressCodeImages()->orderBy('id')->get()->map(fn ($image) => [
                'source' => $image->source,
                'url' => $image->source === 'upload' && $image->image_path ? Storage::disk('public')->url($image->image_path) : null,
                'bankKey' => $image->bank_key,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function rsvpData(Events $event, Guest $guest): array
    {
        $window = $this->rsvpWindow($event);

        return [
            'guest' => ['id' => $guest->id, 'name' => $guest->name, 'status' => $guest->rsvp_status],
            'companions' => $guest->companions()->orderBy('id')->get()
                ->map(fn (Guest $companion) => [
                    'id' => $companion->id,
                    'name' => $companion->name,
                    'status' => $companion->rsvp_status,
                ])->all(),
            'deadline' => $window['deadline'],
            'isOpen' => $window['isOpen'],
        ];
    }

    /** @return array{deadline: ?string, isOpen: bool} */
    private function rsvpWindow(Events $event): array
    {
        $raw = Rsvp::query()->where('event_id', $event->id)->value('confirmation_deadline');

        if (! $raw) {
            return ['deadline' => null, 'isOpen' => true];
        }

        $deadline = Carbon::parse($raw, $event->timezone);

        return [
            'deadline' => $deadline->format('Y-m-d\TH:i:s'),
            'isOpen' => now()->lessThanOrEqualTo($deadline),
        ];
    }
}
