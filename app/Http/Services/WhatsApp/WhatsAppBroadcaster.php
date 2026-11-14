<?php

namespace App\Http\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Events;
use App\Models\Guest;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\OptOut;
use App\Support\WhatsApp\Phone;
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

    public function __construct(private readonly WhatsAppService $whatsapp, private readonly MessageComposer $composer, private readonly DailyLimit $limits) {}

    /** @return array{batchId?: string, queued: int, skipped: list<array{guestId: int, guestName: string, reason: string}>} */
    public function send(Events $event, string $template, string $audience, ?array $guestIds = null, bool $force = false, bool $dryRun = false): array
    {
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

        $recent = $force ? [] : Message::query()
            ->where('event_id', $event->id)->where('template_key', $template)->where('type', 'template')
            ->whereIn('guest_id', $guests->pluck('id'))->where('status', '!=', 'failed')
            ->where('queued_at', '>=', now()->subDay())->pluck('guest_id')->flip()->all();

        // Daily cap (audit W-01): nothing left today is a 429; a partial budget queues the first guests and skips the rest.
        $budget = $this->limits->remaining($event);
        if ($budget <= 0 && ! $dryRun) {
            throw new HttpResponseException(response()->json(['message' => __('errors.whatsapp.daily_limit'), 'code' => 'whatsapp_daily_limit'], 429));
        }
        $optedOut = OptOut::among($guests->map(fn (Guest $g) => Phone::normalize($g->phone))->filter()->all());

        $batch = (string) Str::uuid();
        $skipped = [];
        $created = [];

        DB::transaction(function () use ($event, $template, $guests, $recent, $batch, $dryRun, $optedOut, &$budget, &$skipped, &$created) {
            foreach ($guests as $guest) {
                $phone = Phone::normalize($guest->phone);
                $reason = match (true) {
                    $guest->whatsapp_opted_out_at !== null || ($phone !== null && isset($optedOut[$phone])) => 'opted_out',
                    trim((string) $guest->phone) === '' => 'no_phone',
                    $phone === null => 'invalid_phone',
                    $template === 'reminder_pending' && $guest->rsvp_status !== 'pending' => 'already_responded',
                    isset($recent[$guest->id]) => 'recently_sent',
                    $budget <= 0 => 'daily_limit',
                    default => null,
                };

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
        });

        if ($dryRun) {
            return ['queued' => count($created), 'skipped' => $skipped];
        }

        foreach ($created as $message) {
            SendWhatsAppMessage::dispatch($message->id);
        }

        return ['batchId' => $batch, 'queued' => count($created), 'skipped' => $skipped];
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
