<?php

namespace App\Http\Services\AppServices;

use App\Http\Services\Logger\EventActivityLogger;
use App\Http\Services\Payments\PurchaseService;
use App\Models\BackgroundMusic;
use App\Models\DressCode;
use App\Models\EventLocation;
use App\Models\Events;
use App\Models\Guest;
use App\Models\GuestRsvpLog;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion;
use App\Models\Padrino;
use App\Models\Rsvp;
use App\Models\SaveTheDate;
use App\Models\SweetMemoriesImage;
use App\Models\TemplatePackage;
use App\Models\Timeline;
use App\Support\InvitationCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    private const SECTION_TYPES = ['cover', 'itinerary', 'locations', 'dress_code', 'padrinos', 'gallery', 'rsvp', 'music', 'closing', 'comments', 'tables', 'suggested_music'];

    /** Hard cap of photos in the gallery, whatever the section says. */
    public const GALLERY_MAX_PHOTOS = 40;

    private const GALLERY_DEFAULT_PHOTOS = 12;

    private const MUSIC_TITLE_MAX = 60;

    /** The catalog module that owns each public section; a section whose module is not active for the event is left out. */
    private const SECTION_MODULES = [
        'itinerary' => 'timeline',
        'locations' => 'locations',
        'dress_code' => 'dress_code',
        'padrinos' => 'padrinos',
        'gallery' => 'memories',
        'music' => 'background_music',
        'rsvp' => 'rsvp',
        'comments' => 'comments',
        'tables' => 'seating',
        'suggested_music' => 'suggested_music',
    ];

    /**
     * Only published events have a public invitation, plus the ones archived because their owner deleted her
     * account: the links already sent keep opening, read only, until the event is purged (see rsvpWindow).
     */
    public function findEvent(string $slug): ?Events
    {
        return Events::query()
            ->where('custom_url_slug', $slug)
            ->where(fn ($q) => $q->where('status', 'published')->orWhere(fn ($q) => $q->accountArchived()))
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

    /**
     * @param  bool  $preview  the organizer's own preview: the plan does not hide a premium template or a locked field,
     *                         because looking is allowed (applying is what the plan blocks)
     */
    public function build(Events $event, Guest $guest, ?string $lang = null, bool $preview = false): array
    {
        $locale = $this->resolveLocale($lang, $guest, $event);

        // The organizer's preview reads her unsaved choices: never from, nor into, the shared cache.
        $shared = $preview
            ? $this->sharedPayload($event, $locale, true)
            : InvitationCache::remember($event->id, $locale, fn () => $this->sharedPayload($event, $locale, false));

        return $this->forGuest($shared, $event, $guest);
    }

    /**
     * Everything of the invitation that is the same for every guest of the event in this locale (and so is cached):
     * the guest's own parts are left as a placeholder and filled by forGuest.
     *
     * @return array<string, mixed>
     */
    private function sharedPayload(Events $event, string $locale, bool $preview): array
    {
        $version = $this->versionContent($event, $preview);
        $unlocked = app(PurchaseService::class)->isUnlocked($event);
        $config = $event->template_config ?? [];
        // A draft that is not fixed yet and not paid (a refund) does not keep the paid look: free fields only (D-4).
        // A fixed event keeps what it paid for, and so does a preview.
        if (! $preview && ! $unlocked && $event->template_version_id === null) {
            if (($version['package_id'] ?? null) !== null) {
                $config = app(Invitation\EditableBinding::class)->withoutFullPlan($config, $this->editableFields($version['package_id']));
            } else {
                // The engine's own Classic has no manifest: its free tier is photo, texts and palette; free colors and fonts are the full event's.
                unset($config['theme']['colors'], $config['theme']['fonts']);
            }
        }
        // The package pinned by the version, when it can be drawn; otherwise the invitation falls back to Classic.
        $package = app(PackagePayload::class)->forPackageId($version['package_id'] ?? null, $config['package']['options'] ?? []);

        return [
            'event' => [
                'name' => $event->event_name,
                'typeSlug' => $event->eventType?->slug,
                // Wall-clock time in the event timezone: render it without converting.
                'startsAt' => $event->start_date->format('Y-m-d\TH:i:s'),
                'timezone' => $event->timezone,
            ],
            'guest' => null,
            // Which version of the link this is: the Save the date or the full invitation.
            'stage' => $event->invitation_stage,
            'locale' => $locale,
            'theme' => $this->themePayload($version['theme'], $config['theme'] ?? [], $package['package'] ?? null),
            'sections' => $this->attachPackage($this->resolveSections($event, null, $version['sections'], $locale, $config), $package['sections'] ?? []),
            // Basic-plan watermark; the full event (paid, or on the premium plan) removes it. A refund puts the event back on basic.
            'branding' => ['watermark' => ! $unlocked],
        ];
    }

    /**
     * The shared payload plus what is only this guest's: her name and the RSVP section (answers, companions, note, and
     * the deadline state, which is read live because it changes with time).
     *
     * @param  array<string, mixed>  $shared
     * @return array<string, mixed>
     */
    private function forGuest(array $shared, Events $event, Guest $guest): array
    {
        $shared['guest'] = ['name' => $guest->name];
        $sections = [];

        foreach ($shared['sections'] as $section) {
            if ($section['type'] === 'rsvp') {
                $section = array_replace($section, ['data' => $this->rsvpData($event, $guest)]);
            } elseif ($section['type'] === 'tables') {
                // Only this guest's own table; with none assigned the section is left out, as any empty section.
                $tables = app(Invitation\InvitationTables::class)->forGuest($guest);

                if ($tables === null) {
                    continue;
                }

                $section = array_replace($section, ['data' => $tables]);
            } elseif ($section['type'] === 'suggested_music') {
                // How many of her five suggestions this guest has used; the rest of the data is shared.
                $section['data']['mySuggestions'] = app(Invitation\InvitationSongs::class)->mySuggestions($event, $guest);
            }

            $sections[] = $section;
        }

        $shared['sections'] = $sections;

        return $shared;
    }

    /**
     * Save the guest's answer for themselves and their companions.
     *
     * @param  array{guest: array{rsvpStatus: string, menuItemIds?: list<int>}, companions?: array<int, array{id: int, rsvpStatus: string, name?: string, menuItemIds?: list<int>}>, note?: ?string}  $payload
     * @return array<string, mixed> The refreshed rsvp section data
     *
     * @throws ValidationException
     */
    public function saveRsvp(Events $event, Guest $guest, array $payload): array
    {
        // Nobody answers a Save the date: the invitation with the RSVP comes later, on the same link.
        if ($event->invitation_stage === Events::STAGE_SAVE_THE_DATE) {
            throw ValidationException::withMessages(['rsvp' => 'closed']);
        }

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

        // Menu dishes are checked before anything is written; null when the event has no menu step.
        $menuPicks = app(Invitation\InvitationMenu::class)->validated($event, $guest, $payload);

        $mainStatus = $payload['guest']['rsvpStatus'];
        $previousStatus = $guest->rsvp_status;

        DB::transaction(function () use ($guest, $companions, $requested, $mainStatus, $payload, $menuPicks) {
            $this->setStatus($guest, $mainStatus);

            // Naming the places the organizer assigned never adds or removes people and leaves no status record.
            foreach ($companions as $id => $companion) {
                $name = isset($requested[$id]['name']) ? trim($requested[$id]['name']) : null;

                if ($name !== null && $name !== $companion->name) {
                    $companion->forceFill(['name' => $name])->save();
                }
            }

            if (array_key_exists('note', $payload)) {
                $this->setNote($guest, $payload['note']);
            }

            foreach ($companions as $id => $companion) {
                // Nobody attends under an invitation the main guest declined.
                $status = $mainStatus === self::STATUS_NOT_ATTENDING
                    ? self::STATUS_NOT_ATTENDING
                    : ($requested[$id]['rsvpStatus'] ?? null);

                if ($status !== null) {
                    $this->setStatus($companion, $status);
                }

                if ($menuPicks !== null) {
                    app(Invitation\InvitationMenu::class)->save($companion, $status ?? $companion->rsvp_status, $menuPicks[(string) $id] ?? null);
                }
            }

            if ($menuPicks !== null) {
                app(Invitation\InvitationMenu::class)->save($guest, $mainStatus, $menuPicks['guest'] ?? null);
            }
        });

        Cache::forget("event.{$event->id}.rsvp.stats");

        $this->logRsvp($event, $guest, $previousStatus, $mainStatus);

        return $this->rsvpData($event, $guest->fresh());
    }

    /**
     * First time a guest opens the link: stamps it and tells the feed, once. The conditional update is the lock, so two
     * simultaneous first visits log a single entry. Later visits (and 304s) never reach here.
     */
    public function markOpened(Events $event, Guest $guest): void
    {
        $stamped = Guest::query()->whereKey($guest->id)->whereNull('invitation_opened_at')
            ->update(['invitation_opened_at' => now()]);

        if ($stamped === 1 && $event->status === 'published') {
            EventActivityLogger::log($event->id, 'invitation_opened', $guest, $guest, ['name' => $guest->name]);
        }
    }

    /**
     * The feed hears about the main guest's answer: confirmed or declined the first time, "changed" afterwards.
     * Nothing is logged when the status did not move; bursts from the same guest collapse into one entry.
     */
    private function logRsvp(Events $event, Guest $guest, ?string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $payload = ['name' => $guest->name];

        if ($from === null || $from === self::STATUS_PENDING) {
            $type = $to === self::STATUS_ATTENDING ? 'guest_confirmed' : 'guest_declined';
        } else {
            $type = 'guest_rsvp_changed';
            $payload += ['from' => $from, 'to' => $to];
        }

        if ($to === self::STATUS_ATTENDING) {
            $payload['companions'] = $guest->companions()->where('rsvp_status', self::STATUS_ATTENDING)->count();
        }

        EventActivityLogger::logOnce($event->id, $type, $guest, $guest, $payload);
    }

    /** A missing note is not this method's business; an empty one clears the note. */
    private function setNote(Guest $guest, ?string $note): void
    {
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($guest->rsvp_note === $note) {
            return;
        }

        $guest->forceFill(['rsvp_note' => $note, 'rsvp_note_at' => $note === null ? null : now()])->save();
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

    public function resolveLocale(?string $lang, Guest $guest, Events $event): string
    {
        foreach ([$lang, $guest->locale, $event->locale] as $candidate) {
            if (in_array($candidate, self::LOCALES, true)) {
                return $candidate;
            }
        }

        return 'es';
    }

    /**
     * The template this event draws: its own when it is active and allowed, otherwise the free one of its type. A
     * premium template on an event that is neither fixed nor paid (a refund) is not drawn either (D-4): the free one
     * is. A fixed event never goes through here for its version, so it keeps what it paid for.
     *
     * @param  bool  $ignorePlan  the organizer's preview may look at any template the catalog offers
     */
    public function templateFor(Events $event, bool $ignorePlan = false): InvitationTemplate
    {
        $own = $event->template;
        if ($own && $own->is_active && app(TemplateCatalog::class)->allows($own, $event)
            && ($ignorePlan || $this->planAllows($own, $event))) {
            return $own;
        }

        $free = InvitationTemplate::query()
            ->where('is_active', true)
            ->where('tier', InvitationTemplate::TIER_FREE);

        return (clone $free)->where('event_type_id', $event->event_type_id)->first()
            ?? (clone $free)->whereNull('event_type_id')->first()
            ?? $free->firstOrFail();
    }

    private function planAllows(InvitationTemplate $template, Events $event): bool
    {
        return $template->tier !== InvitationTemplate::TIER_PREMIUM
            || $event->template_version_id !== null
            || app(PurchaseService::class)->isUnlocked($event);
    }

    /**
     * The sections and theme this event shows: the version fixed when it was first published, or (while it has
     * none) the published version of its template. The JSON of a version never changes once it is out of draft,
     * so it is cached by version id.
     *
     * @return array{sections: array<int, array<string, mixed>>, theme: array<string, mixed>, package_id?: ?int}
     */
    public function versionContent(Events $event, bool $ignorePlan = false): array
    {
        if ($event->template_version_id) {
            return $this->cachedVersion($event->template_version_id)
                ?? $this->templateContent($this->templateFor($event, $ignorePlan));
        }

        return $this->templateContent($this->templateFor($event, $ignorePlan));
    }

    /** The version id the event draws now: the fixed one, or the published version of its template. */
    public function currentVersionId(Events $event, bool $ignorePlan = false): ?int
    {
        if ($event->template_version_id && $this->cachedVersion($event->template_version_id)) {
            return $event->template_version_id;
        }

        return $this->templateFor($event, $ignorePlan)->current_version_id;
    }

    /** `editable.json` fields of a package (empty when it cannot be edited: withdrawn or missing). @return list<array<string, mixed>> */
    public function editableFields(?int $packageId): array
    {
        if ($packageId === null) {
            return [];
        }
        $package = TemplatePackage::query()->whereKey($packageId)->first(['id', 'status', 'editable']);

        return $package === null || $package->isWithdrawn() ? [] : array_values((array) ($package->editable['fields'] ?? []));
    }

    /** The catalog module that owns a section type, when it has one. */
    public static function sectionModule(string $type): ?string
    {
        return self::SECTION_MODULES[$type] ?? null;
    }

    /**
     * Fixes the template version on an event that is being published (the caller saves it): from then on the
     * invitation keeps this look even if the template publishes a new version. Already fixed events keep theirs.
     *
     * The Save the date does not fix anything (D-3): the organizer can still change template until the full invitation
     * goes out, and upgrading from the Save the date fixes the version again, with what she chose in the meantime.
     */
    public function pinVersion(Events $event, string $stage = Events::STAGE_INVITATION, bool $repin = false): void
    {
        if ($stage === Events::STAGE_SAVE_THE_DATE) {
            return;
        }
        if ($event->template_version_id === null || $repin) {
            try {
                $event->template_version_id = $this->templateFor($event)->current_version_id;
            } catch (ModelNotFoundException) {
                // No template exists yet (fresh install without seed): nothing to fix.
            }
        }
    }

    /** @return array{sections: array<int, array<string, mixed>>, theme: array<string, mixed>} */
    private function templateContent(InvitationTemplate $template): array
    {
        if ($template->current_version_id && ($content = $this->cachedVersion($template->current_version_id))) {
            return $content;
        }

        // A template without versions (data that never went through the backfill): the old columns.
        return ['sections' => $template->default_sections ?? [], 'theme' => $template->theme_defaults ?? []];
    }

    /** @return array{sections: array<int, array<string, mixed>>, theme: array<string, mixed>}|null */
    private function cachedVersion(int $versionId): ?array
    {
        $key = InvitationCache::byIdKey('invitation.version', $versionId);

        if (($hit = Cache::get($key)) !== null) {
            return $hit;
        }

        $version = InvitationTemplateVersion::query()->find($versionId);

        if (! $version) {
            return null;
        }

        $content = ['sections' => $version->sections ?? [], 'theme' => $version->theme ?? [], 'package_id' => $version->package_id];

        // A draft can still change: only published and archived versions are immutable.
        if (! $version->isDraft()) {
            Cache::put($key, $content, InvitationCache::BY_ID_TTL);
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $defaults  the theme of the version
     * @param  array<string, mixed>  $override  what the organizer changed on the event
     * @param  array<string, mixed>|null  $package  `theme.package` of the installed package this version belongs to, when it can be drawn
     * @return array<string, mixed>
     */
    public function themePayload(array $defaults, array $override = [], ?array $package = null): array
    {
        $presets = $defaults['presets'] ?? [];
        $key = $override['preset'] ?? $defaults['preset'] ?? array_key_first($presets);
        $colors = $presets[$key]['colors'] ?? ($presets[array_key_first($presets)]['colors'] ?? []);
        $name = $defaults['theme'] ?? 'classic';

        $theme = [
            // A package theme that cannot be drawn (withdrawn, missing, engine too old) is Classic with its own colors.
            'name' => $package === null && $name !== 'classic' ? 'classic' : $name,
            'preset' => $key,
            'colors' => array_merge($colors, $override['colors'] ?? []),
            'fonts' => array_merge($defaults['fonts'] ?? [], $override['fonts'] ?? []),
        ];

        return $package === null ? $theme : $theme + ['package' => $package];
    }

    /**
     * Sections the package draws itself carry `render: 'package'` (their AST travels once, in `theme.package.templates`);
     * the rest keep the engine's components.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, array>  $asts  AST per section type
     * @return list<array<string, mixed>>
     */
    public function attachPackage(array $sections, array $asts): array
    {
        if ($asts === []) {
            return $sections;
        }

        return array_map(fn (array $section) => isset($asts[$section['type']])
            ? $section + ['render' => 'package']
            : $section, $sections);
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveSections(Events $event, ?Guest $guest, array $templateSections, string $locale, array $config): array
    {
        if ($event->invitation_stage === Events::STAGE_SAVE_THE_DATE) {
            // The Save the date is the `save_the_date` module: locked, there is nothing to show yet.
            return app(EventModules::class)->isActive($event, 'save_the_date')
                ? $this->saveTheDateSections($event, $locale)
                : [];
        }

        $modules = app(EventModules::class);

        $base = collect($templateSections)->keyBy('type');
        $overrides = collect($config['sections'] ?? [])->keyBy('type');

        return $base->keys()->merge($overrides->keys())->unique()
            ->filter(fn (string $type) => in_array($type, self::SECTION_TYPES, true))
            ->map(fn (string $type) => array_merge($base[$type] ?? ['type' => $type, 'enabled' => false, 'order' => 99], $overrides[$type] ?? []))
            ->filter(fn (array $section) => $section['enabled'] ?? false)
            ->filter(fn (array $section) => ! isset(self::SECTION_MODULES[$section['type']])
                || $modules->isActive($event, self::SECTION_MODULES[$section['type']]))
            ->sortBy(fn (array $section) => $section['order'] ?? 99)
            ->map(function (array $section) use ($event, $guest, $locale) {
                $data = $this->sectionData($section['type'], $event, $guest, $section, $locale);

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
                ] + $this->settingsEntry($section, $locale);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The Save the date: who celebrates and when, a countdown, "add to calendar" and a short message.
     * Nothing that is not decided yet (places, program, dress code, RSVP).
     *
     * @return list<array<string, mixed>>
     */
    private function saveTheDateSections(Events $event, string $locale): array
    {
        $settings = SaveTheDate::query()->where('event_id', $event->id)->first();
        $showCountdown = $settings?->show_countdown ?? true;
        $showCalendar = $settings?->use_add_to_calendar ?? true;
        $english = $locale === 'en';
        $startsAt = $event->start_date->format('Y-m-d\TH:i:s');

        $sections = [[
            'type' => 'cover',
            'title' => $english ? 'Save the date' : 'Guarda la fecha',
            'text' => null,
            'data' => ['eventName' => $event->event_name, 'description' => null, 'image' => null],
        ]];

        if ($showCountdown) {
            $sections[] = [
                'type' => 'countdown',
                'title' => $english ? 'Counting the days' : 'Faltan',
                'text' => null,
                'data' => ['startsAt' => $startsAt, 'timezone' => $event->timezone],
            ];
        }

        $message = $settings?->message;
        if ($message || $showCalendar) {
            $sections[] = [
                'type' => 'save_the_date',
                'title' => null,
                'text' => null,
                'data' => [
                    'message' => $message,
                    'calendar' => $showCalendar ? ['google' => $this->googleCalendarUrl($event), 'icsPath' => 'calendar.ics'] : null,
                ],
            ];
        }

        $sections[] = [
            'type' => 'closing',
            'title' => $english ? 'More details soon' : 'Pronto más detalles',
            'text' => $english ? 'The full invitation will reach you on this same link.' : 'La invitación completa te llegará por este mismo enlace.',
            'data' => (object) [],
        ];

        return $sections;
    }

    private function googleCalendarUrl(Events $event, bool $withPlace = false): string
    {
        $start = $event->start_date;
        $end = $event->end_date ?? $start->copy()->addHours(4);
        $place = $withPlace ? $this->mainPlaceText($event) : null;

        return 'https://calendar.google.com/calendar/render?' . http_build_query(array_filter([
            'action' => 'TEMPLATE',
            'text' => $event->event_name,
            'dates' => $start->format('Ymd\THis') . '/' . $end->format('Ymd\THis'),
            'ctz' => $event->timezone,
            'location' => $place,
        ], fn ($value) => $value !== null));
    }

    /** The main place of the event (the ceremony first) as one line of text, or null without places. */
    private function mainPlace(Events $event): ?EventLocation
    {
        $order = array_flip(EventLocation::TYPES);

        return EventLocation::query()->where('event_id', $event->id)->orderBy('id')->get()
            ->sortBy([fn ($a, $b) => ($order[$a->type] ?? 99) <=> ($order[$b->type] ?? 99), fn ($a, $b) => $a->id <=> $b->id])
            ->first();
    }

    private function mainPlaceText(Events $event): ?string
    {
        $place = $this->mainPlace($event);
        $text = $place ? implode(', ', array_filter([$place->name, $place->address, $place->city, $place->state])) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * What the guest needs to keep the date after answering: the event's own title, start and end with its zone, the
     * main place and the links. Nothing of other guests or of the owner.
     *
     * @return array<string, mixed>
     */
    private function calendarData(Events $event): array
    {
        $start = $event->start_date;
        $end = $event->end_date ?? $start->copy()->addHours(4);
        $place = $this->mainPlace($event);

        return [
            'title' => $event->event_name,
            // Wall-clock time in the event timezone: render it without converting.
            'startsAt' => $start->format('Y-m-d\TH:i:s'),
            'endsAt' => $end->format('Y-m-d\TH:i:s'),
            'timezone' => $event->timezone,
            'location' => $place ? [
                'name' => $place->name,
                'address' => implode(', ', array_filter([$place->address, $place->city, $place->state])) ?: null,
                'links' => $this->routeLinks($place),
            ] : null,
            'google' => $this->googleCalendarUrl($event, true),
            'icsPath' => 'calendar.ics',
        ];
    }

    /** The event as an .ics file. Times are the event's local time with its zone id. */
    public function calendarFile(Events $event): string
    {
        $start = $event->start_date;
        $end = $event->end_date ?? $start->copy()->addHours(4);
        $zone = $event->timezone ?: 'UTC';
        $escape = fn (string $text) => str_replace([',', ';', "\n"], ['\\,', '\\;', '\\n'], $text);

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Quinvite//ES',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            "UID:event-{$event->id}@quinvite",
            'DTSTAMP:' . now()->utc()->format('Ymd\THis\Z'),
            "DTSTART;TZID={$zone}:" . $start->format('Ymd\THis'),
            "DTEND;TZID={$zone}:" . $end->format('Ymd\THis'),
            'SUMMARY:' . $escape((string) $event->event_name),
            ...(($place = $this->mainPlaceText($event)) !== null ? ['LOCATION:' . $escape($place)] : []),
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    /** @param string|array<string, ?string>|null $value */
    public function localized(string|array|null $value, string $locale): ?string
    {
        if (is_array($value)) {
            return $value[$locale] ?? $value['es'] ?? null;
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function sectionData(string $type, Events $event, ?Guest $guest, array $section, string $locale): ?array
    {
        return match ($type) {
            'cover' => [
                'eventName' => $event->event_name,
                'description' => $event->event_description,
                'image' => $this->coverImage($section['settings']['image'] ?? null, $event),
            ] + $this->coverFocus($section),
            'itinerary' => $this->itineraryData($event),
            'locations' => $this->locationsData($event),
            'dress_code' => $this->dressCodeData($event),
            'padrinos' => $this->padrinosData($event),
            'gallery' => $this->galleryData($event, $this->gallerySettings($section)),
            'music' => $this->musicData($event, $section, $locale),
            // Filled per guest by forGuest (null here: the shared payload has no guest).
            'rsvp' => $guest === null ? ['pending' => true] : $this->rsvpData($event, $guest),
            'closing' => [],
            'comments' => app(Invitation\InvitationComments::class)->sectionData($event),
            // Filled per guest by forGuest (and dropped there when she has no table): the shared payload has no guest.
            'suggested_music' => app(Invitation\InvitationSongs::class)->sectionData($event),
            'tables' => $guest === null ? ['pending' => true] : app(Invitation\InvitationTables::class)->forGuest($guest),
            default => null,
        };
    }

    /**
     * The cover photo as an address: a photo the app stored (`upload:`) is served only when it belongs to this event,
     * and a path that starts with `/` (seeders, legacy) is kept as it is.
     */
    public function coverImage(mixed $value, Events $event): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (! str_starts_with($value, Invitation\CoverPhoto::PREFIX)) {
            return $value;
        }
        $path = Invitation\CoverPhoto::pathFor($value, $event->id);

        return $path === null ? null : Storage::disk(Invitation\CoverPhoto::DISK)->url($path);
    }

    /** The point of the photo that must stay visible (`"50% 30%"`), null when the organizer chose none. @return array{focus: ?string} */
    private function coverFocus(array $section): array
    {
        $focus = $section['settings']['focus'] ?? null;

        return ['focus' => is_string($focus) && preg_match('/^\d{1,3}% \d{1,3}%$/', $focus) === 1 ? $focus : null];
    }

    /**
     * "Con el cariño de": the padrinos the organizer chose to show, with what they sponsor and an optional message.
     * Premium: shown when the catalog has `padrinos` active (checked by resolveSections) AND the plan is above the
     * entry one, as before the catalog existed. Never amounts or payments.
     *
     * @return array<string, mixed>|null
     */
    private function padrinosData(Events $event): ?array
    {
        if (! app(PlanFeatures::class)->isPremium($event)) {
            return null;
        }

        $padrinos = Padrino::query()
            ->with('commitments.item')
            ->where('event_id', $event->id)
            ->where('public_thanks', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($padrinos->isEmpty()) {
            return null;
        }

        return ['items' => $padrinos->map(fn (Padrino $padrino) => [
            'name' => $padrino->name,
            'relationship' => $padrino->relationship,
            'sponsors' => $padrino->commitments->map(fn ($c) => $c->item?->title)->filter()->values()->all(),
            'thanks' => $padrino->thanks_text,
        ])->all()];
    }

    /** The program of the day, in order; left out when it has no moments. @return array<string, mixed>|null */
    private function itineraryData(Events $event): ?array
    {
        $moments = Timeline::query()->where('event_id', $event->id)->orderBy('start_time')->orderBy('id')->get();

        if ($moments->isEmpty()) {
            return null;
        }

        return ['items' => $moments->map(fn (Timeline $moment) => [
            'type' => $moment->type,
            'title' => $moment->title,
            'description' => $moment->description,
            'startTime' => $moment->start_time->format('Y-m-d H:i:s'),
            'endTime' => $moment->end_time?->format('Y-m-d H:i:s'),
        ])->all()];
    }

    /** @return array<string, mixed>|null */
    private function locationsData(Events $event): ?array
    {
        $locations = EventLocation::query()
            ->with('eventLocationImages')
            ->where('event_id', $event->id)
            ->orderBy('id')
            ->get();

        if ($locations->isEmpty()) {
            return null;
        }

        // Ceremony and reception show the time of the same kind of moment in the program of the day.
        $moments = Timeline::query()->where('event_id', $event->id)->whereIn('type', ['ceremony', 'reception'])
            ->orderBy('start_time')->get()->groupBy('type');

        $order = array_flip(EventLocation::TYPES);

        return [
            'items' => $locations
                ->sortBy([fn ($a, $b) => $order[$a->type] <=> $order[$b->type], fn ($a, $b) => $a->id <=> $b->id])
                ->map(function (EventLocation $location) use ($moments) {
                    $moment = $moments->get($location->type)?->first();

                    return [
                        'type' => $location->type,
                        'name' => $location->name,
                        'address' => $location->address,
                        'city' => $location->city,
                        'state' => $location->state,
                        'zipCode' => $location->zip_code,
                        'country' => $location->country,
                        'directions' => $location->notes,
                        'latitude' => $location->latitude !== null ? (float) $location->latitude : null,
                        'longitude' => $location->longitude !== null ? (float) $location->longitude : null,
                        'startTime' => $moment?->start_time->format('Y-m-d H:i:s'),
                        'endTime' => $moment?->end_time?->format('Y-m-d H:i:s'),
                        'images' => $location->eventLocationImages->pluck('path')->all(),
                        'links' => $this->routeLinks($location),
                    ];
                })->values()->all(),
        ];
    }

    /** "How to get there" buttons: Google Maps, Apple Maps and Waze. @return array{google: string, apple: string, waze: string} */
    private function routeLinks(EventLocation $location): array
    {
        $hasPoint = $location->latitude !== null && $location->longitude !== null;
        $text = implode(', ', array_filter([$location->name, $location->address, $location->city, $location->state]));
        $point = $hasPoint ? "{$location->latitude},{$location->longitude}" : null;

        return [
            'google' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($point ?? $text),
            'apple' => 'https://maps.apple.com/?' . http_build_query(['q' => $location->name, ($point ? 'll' : 'address') => $point ?? $text]),
            'waze' => 'https://waze.com/ul?' . http_build_query($hasPoint ? ['ll' => $point, 'navigate' => 'yes'] : ['q' => $text, 'navigate' => 'yes']),
        ];
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
                'url' => $image->source === 'upload' && $image->image_path ? Storage::disk(config('filesystems.media_disk'))->url($image->image_path) : null,
                'bankKey' => $image->bank_key,
            ])->all(),
        ];
    }

    /**
     * The `settings` key of a section, limited to a whitelist per type (never the raw JSON of the event); only the
     * sections that have settings in the contract (gallery, music) carry it.
     *
     * @return array{settings?: array<string, mixed>}
     */
    public function settingsEntry(array $section, string $locale): array
    {
        return match ($section['type']) {
            'gallery' => ['settings' => $this->gallerySettings($section)],
            'music' => ['settings' => ['title' => $this->musicTitle($section['settings']['title'] ?? null, $locale)]],
            default => [],
        };
    }

    /** @return array{layout: string, maxPhotos: int, showCaptions: bool} */
    private function gallerySettings(array $section): array
    {
        $raw = is_array($section['settings'] ?? null) ? $section['settings'] : [];
        $max = $raw['maxPhotos'] ?? self::GALLERY_DEFAULT_PHOTOS;

        return [
            'layout' => in_array($raw['layout'] ?? null, ['grid', 'carousel'], true) ? $raw['layout'] : 'grid',
            'maxPhotos' => is_int($max) ? min(max($max, 1), self::GALLERY_MAX_PHOTOS) : self::GALLERY_DEFAULT_PHOTOS,
            'showCaptions' => is_bool($raw['showCaptions'] ?? null) ? $raw['showCaptions'] : true,
        ];
    }

    /** Plain text of up to 60 characters in the requested language, or null. @param string|array<string, ?string>|null $value */
    private function musicTitle(string|array|null $value, string $locale): ?string
    {
        $text = $this->localized($value, $locale);
        $text = is_string($text) ? trim($text) : '';

        return $text === '' ? null : mb_substr($text, 0, self::MUSIC_TITLE_MAX);
    }

    /**
     * The photos of Recuerdos: visible and not hidden by moderation, in the organizer's order. Left out without photos.
     * Premium through the `memories` module (checked by resolveSections).
     *
     * @param  array{layout: string, maxPhotos: int, showCaptions: bool}  $settings
     * @return array<string, mixed>|null
     */
    private function galleryData(Events $event, array $settings): ?array
    {
        $photos = SweetMemoriesImage::query()
            ->where('event_id', $event->id)
            ->where('visible', true)
            ->notHidden()
            ->orderBy('order')
            ->orderBy('id')
            ->limit($settings['maxPhotos'])
            ->get();

        if ($photos->isEmpty()) {
            return null;
        }

        $disk = Storage::disk('sweet-memories');

        return ['photos' => $photos->map(fn (SweetMemoriesImage $photo) => [
            'id' => $photo->id,
            'url' => $disk->url($photo->image_path),
            'thumbUrl' => $photo->thumbnail_path ? $disk->url($photo->thumbnail_path) : $disk->url($photo->image_path),
            'title' => $photo->title,
            'description' => $photo->description,
            'year' => $photo->year !== null && $photo->year !== '' ? (int) $photo->year : null,
        ])->all()];
    }

    /**
     * The background song of the invitation. Never played by the server's choice: no autoplay and no icon settings
     * travel. Left out without a song or when its file is gone.
     *
     * @return array<string, mixed>|null
     */
    private function musicData(Events $event, array $section, string $locale): ?array
    {
        $music = BackgroundMusic::query()->where('event_id', $event->id)->orderByDesc('id')->first();
        $disk = Storage::disk(config('filesystems.media_disk'));

        if (! $music || ! $music->song_url || ! $disk->exists($music->song_url)) {
            return null;
        }

        return [
            'url' => url($disk->url($music->song_url)),
            'title' => $this->musicTitle($section['settings']['title'] ?? null, $locale) ?? $music->title,
            'mimeType' => BackgroundMusicServices::mimeTypeOf($music->song_url),
            'loop' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function rsvpData(Events $event, Guest $guest): array
    {
        $window = $this->rsvpWindow($event);
        // A sample guest (the organizer's preview) is not saved: it has no companions, and must not match "no parent".
        $companions = ! $guest->exists ? collect() : $guest->companions()->orderBy('id')->get();
        $menu = app(Invitation\InvitationMenu::class)->forPeople($event, $guest, [$guest, ...$companions->all()], $window['isOpen']);
        $chosen = $menu['chosen'] ?? [];

        return [
            'guest' => ['id' => $guest->id, 'name' => $guest->name, 'status' => $guest->rsvp_status]
                + ($menu ? ['menuItemIds' => $chosen[$guest->id] ?? []] : []),
            'companions' => $companions->map(fn (Guest $companion) => [
                'id' => $companion->id,
                'name' => $companion->name,
                'status' => $companion->rsvp_status,
            ] + ($menu ? ['menuItemIds' => $chosen[$companion->id] ?? []] : []))->all(),
            // Only this guest's own message, never another family's nor the organizer's note.
            'note' => $guest->rsvp_note,
            'canEditNames' => $window['isOpen'],
            'deadline' => $window['deadline'],
            'isOpen' => $window['isOpen'],
            'calendar' => $this->calendarData($event),
        ] + ($menu ? ['menu' => $menu['menu']] : []);
    }

    /** @return array{deadline: ?string, isOpen: bool} */
    public function rsvpWindow(Events $event): array
    {
        // An invitation kept after its owner deleted her account is read only: nobody can answer it any more.
        if ($event->isAccountArchived()) {
            return ['deadline' => null, 'isOpen' => false];
        }

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
