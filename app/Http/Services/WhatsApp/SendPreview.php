<?php

namespace App\Http\Services\WhatsApp;

use App\Models\Events;
use App\Models\Guest;
use App\Models\WhatsApp\Template;
use Carbon\Carbon;

/**
 * The message the wizard shows in step 2 (docs/plans/guest-send-wizard-contract.md, 4.3): the approved template with the
 * values of one guest filled in, by the same `MessageComposer` the send uses. Nothing is queued, counted or approved.
 */
class SendPreview
{
    public function __construct(private readonly MessageComposer $composer, private readonly WhatsAppService $whatsapp, private readonly WhatsAppBroadcaster $broadcaster) {}

    /**
     * @return array<string, mixed> the `data` of POST preview
     */
    public function render(Events $event, string $template, ?Guest $guest, ?string $locale): array
    {
        $first = $guest ?? Guest::query()->where('event_id', $event->id)->whereNull('parent_id')->orderBy('id')->first();
        // No guests yet: a stand-in, so the organizer still sees how the message reads.
        $subject = $first ?? new Guest(['name' => $this->sampleName($event), 'code' => 'ABC123']);
        $locale ??= $this->composer->localeFor($event, $subject);

        $row = Template::query()->where('key', $template)->where('locale', $locale)->first();
        if ($row === null || ! $this->whatsapp->canSend($template, $locale)) {
            $this->broadcaster->conflict('template_not_approved', __('errors.whatsapp.template_not_approved'));
        }

        $params = $this->composer->params($template, $event, $subject, $locale);

        return [
            'template' => $template,
            'locale' => $locale,
            'category' => $row->category,
            'guest' => $first ? ['id' => $first->id, 'name' => $first->name] : null,
            'sample' => $first === null,
            'body' => $this->composer->render($row, $params),
            'buttons' => collect($row->buttons ?? [])->map(fn (array $b) => ['id' => (string) ($b['id'] ?? ''), 'text' => $b['text'] ?? ''])->values()->all(),
            'link' => $params['link'],
            'sendable' => true,
            // What the wizard needs to draw the cover miniature of the real invitation.
            'event' => [
                'id' => $event->id, 'name' => $event->event_name, 'startDate' => $event->start_date ? Carbon::parse($event->start_date)->utc()->toIso8601String() : null,
                'timezone' => $event->timezone, 'published' => $event->status === 'published',
            ],
            // Filled by the Open Graph phase (F4b): the card WhatsApp will draw for the link. Null until then.
            'linkPreview' => null,
        ];
    }

    private function sampleName(Events $event): string
    {
        return ($event->locale ?? 'es') === 'en' ? 'Guest' : 'Invitado';
    }
}
