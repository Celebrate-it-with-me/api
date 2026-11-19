<?php

namespace App\Http\Services\WhatsApp;

use App\Http\Services\AppServices\EventModules;
use App\Http\Services\AppServices\InvitationService;
use App\Models\Events;
use App\Models\Guest;
use App\Models\WhatsApp\Message;
use Illuminate\Support\Collection;

/**
 * Who a send would reach, for the send wizard (docs/plans/guest-send-wizard-contract.md, 4.1 and 4.2). Families are
 * main guests (companions share the invitation). The reasons come from `WhatsAppBroadcaster::skipReason`, the same
 * code `send` runs, so what the screen promises is what the send does. Read-only: it queues and approves nothing.
 */
class SendAudience
{
    public const CATEGORIES = ['unsent', 'pending', 'all', 'problem'];

    public const BLOCKED = ['no_phone', 'invalid_phone', 'opted_out', 'recently_sent', 'already_responded'];

    public function __construct(
        private readonly WhatsAppBroadcaster $broadcaster,
        private readonly WhatsAppService $whatsapp,
        private readonly MessageComposer $composer,
        private readonly DeliveryTracker $tracker,
        private readonly DailyLimit $limits,
        private readonly EventModules $modules,
        private readonly InvitationService $invitations,
    ) {}

    /** @return array<string, mixed> the `data` of GET audience */
    public function audience(Events $event, string $template): array
    {
        $families = $this->families($event, $template);
        $eligible = $families->whereNull('reason');

        $blocked = array_fill_keys(self::BLOCKED, 0);
        foreach ($families as $family) {
            if ($family['reason'] !== null && array_key_exists($family['reason'], $blocked)) {
                $blocked[$family['reason']]++;
            }
        }

        $locales = $eligible->countBy('locale')->all();
        $window = $this->invitations->rsvpWindow($event);
        $mode = $this->mode($event, $template, $eligible->pluck('locale')->unique()->values()->all() ?: [$this->composer->localeFor($event, new Guest)]);

        return [
            'template' => $template,
            'mode' => $mode,
            'event' => ['published' => $event->status === 'published', 'archived' => $event->isAccountArchived()],
            'rsvp' => ['isOpen' => $window['isOpen'], 'deadline' => $window['deadline']],
            'totalFamilies' => $families->count(),
            'categories' => [
                'unsent' => ['count' => $families->filter(fn (array $f) => $this->inCategory($f, 'unsent'))->count()],
                'pending' => ['count' => $families->filter(fn (array $f) => $this->inCategory($f, 'pending'))->count()],
                'all' => ['count' => $eligible->count()],
            ],
            'blocked' => $blocked,
            'locales' => $locales === [] ? (object) [] : $locales,
            'templates' => ['es' => ['sendable' => $this->whatsapp->canSend($template, 'es')], 'en' => ['sendable' => $this->whatsapp->canSend($template, 'en')]],
            'limits' => [
                'dailyRemaining' => $this->limits->remaining($event),
                'dailyResetsAt' => $this->limits->resetsAt($event)?->utc()->toIso8601String(),
                'perBatchMax' => WhatsAppBroadcaster::MAX_BATCH,
            ],
            // Filled in when the quota/credits contract is decided (phase F6; out of scope for now).
            'quota' => null,
        ];
    }

    /**
     * One page of families for "Elegir invitados".
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function recipients(Events $event, string $template, string $category, ?string $search, int $page, int $perPage): array
    {
        $perPage = min(max($perPage, 1), 100);
        $page = max($page, 1);

        $rows = $this->families($event, $template)
            ->filter(fn (array $f) => $this->inCategory($f, $category))
            ->when(trim((string) $search) !== '', function (Collection $c) use ($search) {
                $term = mb_strtolower(trim((string) $search));

                return $c->filter(fn (array $f) => collect([$f['name'], $f['phone'], $f['email']])
                    ->contains(fn ($v) => $v !== null && str_contains(mb_strtolower((string) $v), $term)));
            });

        $total = $rows->count();
        $data = $rows->slice(($page - 1) * $perPage, $perPage)->values()->map(fn (array $f) => [
            'id' => $f['id'], 'name' => $f['name'], 'people' => $f['people'], 'phone' => $f['phone'], 'locale' => $f['locale'],
            'status' => $f['status'], 'eligible' => $f['reason'] === null, 'reason' => $f['reason'], 'delivery' => $f['delivery'],
        ])->all();

        return ['data' => $data, 'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total]];
    }

    /** Whether a family belongs to a category (see the contract, 4.1 and 4.2). @param array<string, mixed> $f */
    private function inCategory(array $f, string $category): bool
    {
        return match ($category) {
            // Never got this template (a failed attempt does not count) and can get it now.
            'unsent' => $f['reason'] === null && ! $f['sentBefore'],
            // Main guest has not answered, already got something, and a reminder would go through.
            'pending' => $f['reminderReason'] === null && $f['hadMessage'],
            // Everybody, to pick from; the audience counts only the eligible.
            'all' => true,
            'problem' => in_array($f['reason'], ['no_phone', 'invalid_phone', 'opted_out'], true) || $f['delivery']['state'] === 'failed',
            default => false,
        };
    }

    /** @return array{automatic: bool, reason: ?string} */
    private function mode(Events $event, string $template, array $locales): array
    {
        $state = $this->modules->stateOf($event, 'whatsapp');

        $reason = match (true) {
            $state === null => 'module_off',
            $state !== EventModules::ACTIVE => 'module_locked',
            ! $this->whatsapp->isAvailable() => 'whatsapp_unavailable',
            collect($locales)->contains(fn (string $l) => ! $this->whatsapp->canSend($template, $l)) => 'template_not_approved',
            default => null,
        };

        return ['automatic' => $reason === null, 'reason' => $reason];
    }

    /**
     * Every main guest of the event with its reason for the template, delivery and the facts the categories need.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function families(Events $event, string $template): Collection
    {
        $guests = Guest::query()->where('event_id', $event->id)->whereNull('parent_id')->withCount('companions')->orderBy('name')->orderBy('id')->get();
        $ids = $guests->pluck('id')->all();

        $optedOut = $this->broadcaster->optedOutPhones($guests);
        $recent = $this->broadcaster->recentlySent($event, $template, $ids);
        $recentReminder = $this->broadcaster->recentlySent($event, 'reminder_pending', $ids);
        $delivery = $this->tracker->forGuests($event, $guests);

        $messages = $ids === [] ? collect() : Message::query()->where('event_id', $event->id)->where('type', 'template')
            ->whereIn('guest_id', $ids)->where('status', '!=', 'failed')->get(['guest_id', 'template_key']);
        $anyMessage = $messages->pluck('guest_id')->flip();
        $sameTemplate = $messages->where('template_key', $template)->pluck('guest_id')->flip();

        return $guests->map(fn (Guest $g) => [
            'id' => $g->id, 'name' => $g->name, 'email' => $g->email, 'phone' => $g->phone, 'status' => $g->rsvp_status,
            'people' => 1 + (int) $g->companions_count,
            'locale' => $this->composer->localeFor($event, $g),
            'reason' => $this->broadcaster->skipReason($g, $template, $optedOut, $recent),
            'reminderReason' => $this->broadcaster->skipReason($g, 'reminder_pending', $optedOut, $recentReminder),
            'sentBefore' => $sameTemplate->has($g->id),
            'hadMessage' => $anyMessage->has($g->id),
            'delivery' => $delivery[$g->id],
        ])->values();
    }
}
