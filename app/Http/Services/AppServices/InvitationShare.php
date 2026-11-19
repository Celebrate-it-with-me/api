<?php

namespace App\Http\Services\AppServices;

use App\Http\Services\AppServices\Invitation\CoverPhoto;
use App\Models\EventLocation;
use App\Models\Events;
use App\Models\Guest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What a link preview shows (WhatsApp, Facebook, iMessage…) when an invitation link is shared: a title, a line
 * of description and an image. Crawlers do not run the app, so this is plain HTML made by the server.
 * Nothing private goes in it: only what the invitation already says to whoever holds the link.
 */
class InvitationShare
{
    /** Longest guest name the title carries; longer ones are cut with an ellipsis. */
    public const NAME_LIMIT = 40;

    public function __construct(private readonly InvitationService $invitations, private readonly CoverPhoto $covers) {}

    /**
     * What `/og/{slug}/{code}` prints. The guest's name is in the title only here, after the code has been
     * validated; any wrong slug, code, companion code or unpublished event gives null (a uniform 404).
     *
     * @return array{title: string, description: string, image: string, imageWidth: ?int, imageHeight: ?int, imageAlt: string, url: string, locale: string, siteName: string}|null
     */
    public function metadata(string $slug, string $code, ?string $lang): ?array
    {
        $event = $this->invitations->findEvent($slug);
        $guest = $event ? $this->invitations->findGuest($event, $code) : null;

        if (! $event || ! $guest) {
            return null;
        }

        $locale = $this->invitations->resolveLocale($lang, $guest, $event);
        $front = rtrim((string) config('app.frontend_app.url'), '/');
        $image = $this->image($event, $front);

        return [
            'title' => $this->title($event, $guest, $locale),
            'description' => $this->description($event, $locale),
            'image' => $image['url'],
            'imageWidth' => $image['width'],
            'imageHeight' => $image['height'],
            'imageAlt' => $image['alt'],
            'url' => "$front/i/" . rawurlencode($slug) . '/' . rawurlencode($code) . ($lang ? '?lang=' . rawurlencode($lang) : ''),
            'locale' => $locale === 'en' ? 'en_US' : 'es_US',
            'siteName' => 'Quinvite',
        ];
    }

    /**
     * The card of one guest as the send wizard shows it ("así se verá en WhatsApp"): the same title, description and
     * image `/og/...` will print. Unlike the crawler page it does not require the event to be published (the wizard
     * warns about drafts itself); the caller must have authorised access to this guest.
     *
     * @return array{title: string, description: string, image: string, alt: string, imageWidth: ?int, imageHeight: ?int}
     */
    public function linkPreview(Guest $guest, ?string $lang = null): array
    {
        $event = Events::query()->findOrFail($guest->event_id);
        $locale = $this->invitations->resolveLocale($lang, $guest, $event);
        $image = $this->image($event, rtrim((string) config('app.frontend_app.url'), '/'));

        return [
            'title' => $this->title($event, $guest, $locale),
            'description' => $this->description($event, $locale),
            'image' => $image['url'],
            'alt' => $image['alt'],
            'imageWidth' => $image['width'],
            'imageHeight' => $image['height'],
        ];
    }

    /** "{name}, estás invitado/a a {event}" — the name is cleaned and cut; without a usable name, the generic title. */
    private function title(Events $event, Guest $guest, string $locale): string
    {
        $saveTheDate = $event->invitation_stage === Events::STAGE_SAVE_THE_DATE;
        $name = $event->event_name;
        $who = $this->guestName($guest);

        $title = match (true) {
            $locale === 'en' && $saveTheDate => $who ? "$who, save the date: $name" : "Save the date: $name",
            $locale === 'en' => $who ? "$who, you are invited to $name" : "You are invited to $name",
            $saveTheDate => $who ? "$who, guarda la fecha: $name" : "Guarda la fecha: $name",
            default => $who ? "$who, estás invitado/a a $name" : "Estás invitado/a a $name",
        };

        return $title;
    }

    private function guestName(Guest $guest): ?string
    {
        $name = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', (string) $guest->name));

        return $name === '' ? null : Str::limit($name, self::NAME_LIMIT, '…');
    }

    /** "Saturday, December 25, 2026 · Miami" — the date and, when there is one, the city of the first place. */
    private function description(Events $event, string $locale): string
    {
        $date = Carbon::parse($event->start_date)->locale($locale)->isoFormat($locale === 'en' ? 'dddd, MMMM D, YYYY' : 'dddd D [de] MMMM [de] YYYY');
        $city = EventLocation::query()->where('event_id', $event->id)->orderBy('id')->value('city');
        $line = ucfirst($date) . ($city ? " · $city" : '');

        return $locale === 'en' ? "$line. Open your invitation." : "$line. Abre tu invitación.";
    }

    /**
     * The cover photo the guest will see: the organizer's own (`upload:` of THIS event, as a 1200x630 JPEG), her
     * address, or the one of the template; otherwise the brand image. Measures are given only when we know them.
     *
     * @return array{url: string, width: ?int, height: ?int, alt: string}
     */
    private function image(Events $event, string $front): array
    {
        $brand = ['url' => "$front/og-default.png", 'width' => 1200, 'height' => 630, 'alt' => 'Quinvite'];
        $alt = (string) $event->event_name;
        $value = $this->coverSetting($event->template_config['sections'] ?? [], 'image')
            ?? $this->coverSetting($this->invitations->versionContent($event)['sections'] ?? [], 'image');

        if (! is_string($value) || $value === '') {
            return $brand;
        }

        if (str_starts_with($value, CoverPhoto::PREFIX)) {
            // Only a photo of this event's own folder is ever served.
            $path = CoverPhoto::pathFor($value, $event->id);
            $focus = $this->coverSetting($event->template_config['sections'] ?? [], 'focus');
            if ($path === null || ! $this->covers->makeShare($path, is_string($focus) ? $focus : null)) {
                return $brand;
            }

            return [
                'url' => Storage::disk(CoverPhoto::DISK)->url(CoverPhoto::sharePath($path)) . '?v=' . substr(hash('sha256', $path), 0, 10),
                'width' => CoverPhoto::SHARE_WIDTH,
                'height' => CoverPhoto::SHARE_HEIGHT,
                'alt' => $alt,
            ];
        }
        if (str_starts_with($value, 'http')) {
            return ['url' => $value, 'width' => null, 'height' => null, 'alt' => $alt];
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return ['url' => $front . $value, 'width' => null, 'height' => null, 'alt' => $alt];
        }

        return $brand;
    }

    /** @param array<int, mixed> $sections */
    private function coverSetting(array $sections, string $key): mixed
    {
        $found = null;
        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'cover' && ! empty($section['settings'][$key])) {
                $found = $section['settings'][$key];
            }
        }

        return $found;
    }
}
