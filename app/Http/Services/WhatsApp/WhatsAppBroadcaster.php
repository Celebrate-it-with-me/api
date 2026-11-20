<?php

namespace App\Http\Services\WhatsApp;

use App\Http\Services\AppServices\InvitationService;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Events;
use App\Models\Guest;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\OptOut;
use App\Support\PhoneNumber;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Send to guests" (docs/plans/communications-contract.md): picks the guests, skips the ones that must not get the message
 * (with the reason), creates the queued messages and hands them to the `whatsapp` queue. Companions share the main
 * guest's invitation, so only main guests are messaged.
 */
class WhatsAppBroadcaster
{
    public const MAX_BATCH = 500;

    /** An automatic batch that would queue more families than this needs the organizer's explicit confirmation (P7). */
    public const CONFIRM_ABOVE = 100;

    /** A family gets at most this many reminders (P6). */
    public const MAX_REMINDERS = 3;

    public function __construct(private readonly WhatsAppService $whatsapp, private readonly MessageComposer $composer, private readonly DailyLimit $limits, private readonly InvitationService $invitations) {}

    /** @return array{batchId?: string, queued: int, skipped: list<array{guestId: int, guestName: string, reason: string}>} */
    public function send(Events $event, string $template, string $audience, ?array $guestIds = null, bool $force = false, bool $dryRun = false, bool $confirmed = false): array
    {
        $this->assertEventCanSend($event, $template);

        if (! $this->whatsapp->isAvailable()) {
            $this->conflict('whatsapp_unavailable', __('errors.whatsapp.unavailable'));
        }

        if ($audience === 'selected' && empty($guestIds)) {
            throw ValidationException::withMessages(['guest_ids' => ['required']]);
        }

        $guests = $this->candidates($event, $template, $audience, $guestIds);

        if ($guests->isEmpty()) {
            throw ValidationException::withMessages(['audience' => ['no_recipients']]);
        }
        if ($guests->count() > self::MAX_BATCH) {
            throw ValidationException::withMessages(['guest_ids' => ['too_many']]);
        }

        // Every locale in play needs its template ready before anything is queued.
        $locales = $guests->map(fn (Guest $g) => $this->composer->localeFor($event, $g))->unique();
        foreach ($locales as $locale) {
            if (! $this->whatsapp->sendable($template, $locale)) {
                $this->conflict('template_not_approved', __('errors.whatsapp.template_not_approved'));
            }
        }

        // Reading the cap and the recent sends, and queueing, happen under one lock per event (audit M-4).
        [$batch, $skipped, $created] = $this->limits->exclusive($event, function () use ($event, $template, $guests, $force, $dryRun, $confirmed) {
            $recent = $force ? [] : $this->recentlySent($event, $template, $guests->pluck('id')->all());
            // Reminders have a ceiling that `force` does not lift.
            $reminders = $this->reminderCounts($event, $template, $guests->pluck('id')->all());

            // Daily cap (audit W-01): nothing left today is a 429; a partial budget queues the first guests and skips the rest.
            $budget = $this->limits->remaining($event);
            if ($budget <= 0 && ! $dryRun) {
                throw new HttpResponseException(response()->json(['message' => __('errors.whatsapp.daily_limit'), 'code' => 'whatsapp_daily_limit'], 429));
            }
            $optedOut = $this->optedOutPhones($guests);

            $batch = (string) Str::uuid();
            $skipped = [];
            $created = [];

            DB::transaction(function () use ($event, $template, $guests, $recent, $reminders, $batch, $dryRun, $confirmed, $optedOut, &$budget, &$skipped, &$created) {
                foreach ($guests as $guest) {
                    $phone = PhoneNumber::e164($guest->phone);
                    $reason = $this->skipReason($guest, $template, $optedOut, $recent, $reminders) ?? ($budget <= 0 ? 'daily_limit' : null);

                    if ($reason) {
                        $skipped[] = ['guestId' => $guest->id, 'guestName' => $guest->name, 'reason' => $reason];

                        continue;
                    }

                    $budget--;
                    $locale = $this->composer->localeFor($event, $guest);
                    if ($dryRun) {
                        $created[] = $guest->id;

                        continue;
                    }
                    $created[] = Message::create([
                        'event_id' => $event->id, 'guest_id' => $guest->id, 'batch_id' => $batch, 'template_key' => $template, 'locale' => $locale,
                        'type' => 'template', 'to_phone' => $phone, 'params' => $this->composer->params($template, $event, $guest, $locale),
                        'status' => 'queued', 'queued_at' => now(),
                    ]);
                }

                // A big batch is a deliberate act: nothing stays queued (the transaction rolls back) until the caller confirms.
                if (! $dryRun && ! $confirmed && count($created) > self::CONFIRM_ABOVE) {
                    throw new HttpResponseException(response()->json([
                        'message' => __('errors.whatsapp.confirmation_required'), 'code' => 'confirmation_required', 'count' => count($created), 'threshold' => self::CONFIRM_ABOVE,
                    ], 409));
                }
            });

            return [$batch, $skipped, $created];
        });

        if ($dryRun) {
            return ['queued' => count($created), 'skipped' => $skipped, 'requiresConfirmation' => count($created) > self::CONFIRM_ABOVE];
        }

        foreach ($created as $message) {
            SendWhatsAppMessage::dispatch($message->id);
        }

        return ['batchId' => $batch, 'queued' => count($created), 'skipped' => $skipped];
    }

    /**
     * Why a main guest would not get this template (null = eligible), in this order. The daily budget is not here: it is
     * decided while queueing. Shared by `send`, the audience and the recipients list so they never diverge.
     *
     * @param  array<string, true>  $optedOut  E.164 numbers that asked to stop, see `optedOutPhones`
     * @param  array<int, int>  $recent  guest ids (as keys) that got this template in the last 24 h, see `recentlySent`
     * @param  array<int, int>  $reminders  reminders already sent per guest id, see `reminderCounts` (only read for `reminder_pending`)
     */
    public function skipReason(Guest $guest, string $template, array $optedOut, array $recent, array $reminders = []): ?string
    {
        $phone = PhoneNumber::e164($guest->phone);

        return match (true) {
            $guest->whatsapp_opted_out_at !== null || ($phone !== null && isset($optedOut[$phone])) => 'opted_out',
            trim((string) $guest->phone) === '' => 'no_phone',
            $phone === null => 'invalid_phone',
            $template === 'reminder_pending' && $guest->rsvp_status !== 'pending' => 'already_responded',
            $template === 'reminder_pending' && ($reminders[$guest->id] ?? 0) >= self::MAX_REMINDERS => 'reminder_limit',
            isset($recent[$guest->id]) => 'recently_sent',
            default => null,
        };
    }

    /** @param iterable<Guest> $guests @return array<string, true> */
    public function optedOutPhones(iterable $guests): array
    {
        return OptOut::among(collect($guests)->map(fn (Guest $g) => PhoneNumber::e164($g->phone))->filter()->all());
    }

    /** Guests (ids as keys) that already got this template in the last 24 h, failed ones excluded. @param list<int> $guestIds @return array<int, int> */
    public function recentlySent(Events $event, string $template, array $guestIds): array
    {
        if ($guestIds === []) {
            return [];
        }

        return Message::query()
            ->where('event_id', $event->id)->where('template_key', $template)->where('type', 'template')
            ->whereIn('guest_id', $guestIds)->where('status', '!=', 'failed')
            ->where('queued_at', '>=', now()->subDay())->pluck('guest_id')->flip()->all();
    }

    /**
     * Reminders already queued per guest (failed ones do not count), only for the reminder template.
     *
     * @param  list<int>  $guestIds
     * @return array<int, int> guest id => count
     */
    public function reminderCounts(Events $event, string $template, array $guestIds): array
    {
        if ($template !== 'reminder_pending' || $guestIds === []) {
            return [];
        }

        return Message::query()->where('event_id', $event->id)->where('template_key', 'reminder_pending')->where('type', 'template')
            ->whereIn('guest_id', $guestIds)->where('status', '!=', 'failed')
            ->selectRaw('guest_id, count(*) as n')->groupBy('guest_id')->pluck('n', 'guest_id')->map(fn ($n) => (int) $n)->all();
    }

    /** What stops the event from sending a template at all (409 with a code). */
    public function assertEventCanSend(Events $event, string $template): void
    {
        // The owner deleted her account: the invitation is kept to be read, not to message guests.
        if ($event->isAccountArchived()) {
            $this->conflict('event_archived', __('errors.whatsapp.event_archived'));
        }

        // The links only open once the event is published.
        if ($event->status !== 'published') {
            $this->conflict('event_not_published', __('errors.whatsapp.event_not_published'));
        }

        // Nobody could answer an invitation or a reminder after the RSVP deadline; the save the date has no answer.
        if (in_array($template, ['invitation', 'reminder_pending'], true) && ! $this->invitations->rsvpWindow($event)['isOpen']) {
            $this->conflict('rsvp_closed', __('errors.whatsapp.rsvp_closed'));
        }
    }

    private function candidates(Events $event, string $template, string $audience, ?array $guestIds)
    {
        return Guest::query()
            ->where('event_id', $event->id)->whereNull('parent_id')
            ->when($audience === 'pending', fn ($q) => $q->where('rsvp_status', 'pending'))
            ->when($audience === 'selected', fn ($q) => $q->whereIn('id', $guestIds ?? []))
            ->orderBy('id')->get();
    }

    public function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], 409));
    }
}
