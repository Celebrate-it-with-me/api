<?php

namespace App\Http\Services\AppServices;

use App\Models\EventLocation;
use App\Models\Events;
use App\Models\Guest;
use Carbon\Carbon;

/**
 * What a link preview shows (WhatsApp, Facebook, iMessage…) when an invitation link is shared: a title, a line
 * of description and an image. Crawlers do not run the app, so this is plain HTML made by the server.
 * Nothing private goes in it: only what the invitation already says to whoever holds the link.
 */
class InvitationShare
{
    public function __construct(private readonly InvitationService $invitations) {}

    /**
     * @return array{title: string, description: string, image: string, url: string, locale: string, siteName: string}|null
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

        return [
            'title' => $this->title($event, $guest, $locale),
            'description' => $this->description($event, $locale),
            'image' => $this->image($event, $front),
            'url' => "$front/i/" . rawurlencode($slug) . '/' . rawurlencode($code) . ($lang ? '?lang=' . rawurlencode($lang) : ''),
            'locale' => $locale === 'en' ? 'en_US' : 'es_US',
            'siteName' => 'Quinvite',
        ];
    }

    private function title(Events $event, Guest $guest, string $locale): string
    {
        $saveTheDate = $event->invitation_stage === Events::STAGE_SAVE_THE_DATE;
        $name = $event->event_name;

        return match (true) {
            $locale === 'en' && $saveTheDate => "Save the date: $name",
            $locale === 'en' => "You are invited to $name",
            $saveTheDate => "Guarda la fecha: $name",
            default => "Estás invitado/a a $name",
        };
    }

    /** "Saturday, December 25, 2026 · Miami" — the date and, when there is one, the city of the first place. */
    private function description(Events $event, string $locale): string
    {
        $date = Carbon::parse($event->start_date)->locale($locale)->isoFormat($locale === 'en' ? 'dddd, MMMM D, YYYY' : 'dddd D [de] MMMM [de] YYYY');
        $city = EventLocation::query()->where('event_id', $event->id)->orderBy('id')->value('city');
        $line = ucfirst($date) . ($city ? " · $city" : '');

        return $locale === 'en' ? "$line. Open your invitation." : "$line. Abre tu invitación.";
    }

    /** The cover photo when the organizer set one; otherwise the brand image. */
    private function image(Events $event, string $front): string
    {
        $cover = null;
        foreach (($event->template_config['sections'] ?? []) as $section) {
            if (($section['type'] ?? null) === 'cover' && ! empty($section['settings']['image'])) {
                $cover = $section['settings']['image'];
            }
        }

        return is_string($cover) && str_starts_with($cover, 'http') ? $cover : "$front/og-default.png";
    }
}
