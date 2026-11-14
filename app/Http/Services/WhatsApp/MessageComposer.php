<?php

namespace App\Http\Services\WhatsApp;

use App\Http\Services\AppServices\InvitationService;
use App\Models\Events;
use App\Models\Guest;
use App\Models\WhatsApp\Template;
use Carbon\Carbon;

/** Fills a template's fixed variables for one guest (docs/plans/communications-contract.md, "Variables"). */
class MessageComposer
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function localeFor(Events $event, Guest $guest): string
    {
        return $this->invitations->resolveLocale(null, $guest, $event);
    }

    /** @return array<string, string> variable name => value */
    public function params(string $key, Events $event, Guest $guest, string $locale, ?string $rsvpStatus = null): array
    {
        $values = [
            'guest_name' => $this->free((string) $guest->name),
            'event_name' => $this->free((string) $event->event_name),
            'event_date' => $this->date($event, $locale),
            'link' => $this->link($event, $guest),
            'days_left' => $this->daysLeft($event, $locale),
            'status_text' => $this->statusText($rsvpStatus ?? InvitationService::STATUS_ATTENDING, $locale),
        ];

        return array_map(fn ($v) => $this->clean((string) $v), $values);
    }

    /** Parameters in the order the template expects them. @param array<string, string> $params @return list<string> */
    public function positional(Template $template, array $params): array
    {
        return array_map(fn (string $name) => $params[$name] ?? '-', $template->variables);
    }

    /** The template's text with the values filled in (session text replies): `{{n}}` takes the n-th variable. @param array<string, string> $params */
    public function render(Template $template, array $params): string
    {
        return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn ($m) => $params[$template->variables[(int) $m[1] - 1] ?? ''] ?? '', $template->body);
    }

    private function link(Events $event, Guest $guest): string
    {
        return rtrim((string) config('app.frontend_app.url'), '/') . '/i/' . rawurlencode((string) $event->custom_url_slug) . '/' . rawurlencode((string) $guest->code);
    }

    private function date(Events $event, string $locale): string
    {
        $date = Carbon::parse($event->start_date)->setTimezone($event->timezone ?: config('app.timezone'))->locale($locale);

        return $date->isoFormat($locale === 'en' ? 'dddd, MMMM D, YYYY' : 'dddd D [de] MMMM [de] YYYY');
    }

    private function daysLeft(Events $event, string $locale): string
    {
        $days = max(0, (int) ceil(now()->diffInDays(Carbon::parse($event->start_date), false)));

        return match (true) {
            $days === 0 => $locale === 'en' ? 'a few hours' : 'unas horas',
            $days === 1 => $locale === 'en' ? '1 day' : '1 día',
            default => $locale === 'en' ? "$days days" : "$days días",
        };
    }

    private function statusText(string $status, string $locale): string
    {
        $attending = $status === InvitationService::STATUS_ATTENDING;

        return match (true) {
            $locale === 'en' && $attending => 'you are coming, and we cannot wait to see you',
            $locale === 'en' => 'you cannot join us, and we will miss you',
            $attending => 'confirmaste tu asistencia y te esperamos con mucho cariño',
            default => 'nos avisas que no podrás acompañarnos, ¡te vamos a extrañar!',
        };
    }

    /**
     * Free text typed by the organizer (guest and event names) goes into an approved template, so it must not carry
     * links, e-mails, control characters or line breaks, and it is kept short (audit W-01).
     */
    private function free(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F\p{Cc}\p{Cf}]+/u', ' ', $value) ?? '';
        $value = preg_replace('~(?:[a-z][a-z0-9+.-]*://|www\.)\S*~iu', ' ', $value) ?? '';
        $value = preg_replace('~\S+@\S+~u', ' ', $value) ?? '';
        $value = preg_replace('~\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|io|co|me|ly|app|link|xyz|info|us|es|gl|to|cc|tk)\b\S*~iu', ' ', $value) ?? '';
        $value = $this->clean($value);

        return mb_strlen($value) > 60 ? rtrim(mb_substr($value, 0, 60)) : $value;
    }

    /** Meta rejects parameters with line breaks, tabs or long runs of spaces. */
    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
