<?php

namespace App\Http\Services\WhatsApp;

use App\Http\Services\AppServices\InvitationService;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Events;
use App\Models\Guest;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\OptOut;
use App\Models\WhatsApp\Template;
use App\Support\WhatsApp\Phone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything that arrives from WhatsApp, real (webhook job) or simulated (`whatsapp:simulate`): same code path.
 * Each event is claimed in `whatsapp_inbound_events` first, inside the same transaction as its effects, so a repeat
 * does nothing and a failure leaves it unclaimed for the next delivery.
 */
class WebhookProcessor
{
    private const STOP_WORDS = ['stop', 'baja', 'parar', 'no mas'];

    public function __construct(
        private readonly MessageStatus $statuses,
        private readonly InvitationService $invitations,
        private readonly MessageComposer $composer,
    ) {}

    /** @param array<string, mixed> $payload the decoded body of a webhook POST */
    public function process(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                match ($change['field'] ?? null) {
                    'messages' => $this->messages($value),
                    'message_template_status_update' => $this->templateStatus($value),
                    default => null,
                };
            }
        }
    }

    private function messages(array $value): void
    {
        foreach ($value['statuses'] ?? [] as $st) {
            if ($this->parkIfUnknown($st)) {
                continue;
            }
            $this->once('st:' . ($st['id'] ?? '') . ':' . ($st['status'] ?? ''), 'status', fn () => $this->status($st));
        }
        foreach ($value['messages'] ?? [] as $msg) {
            $this->once('msg:' . ($msg['id'] ?? Str::uuid()), 'message', fn () => $this->inbound($msg));
        }
    }

    /**
     * A delivery update can beat the save of the message id (audit W-09). Keep it, unclaimed, and apply it as soon as the id
     * is stored; look again after saving it because the id may have landed in between.
     */
    private function parkIfUnknown(array $st): bool
    {
        $id = (string) ($st['id'] ?? '');
        if ($id === '' || Message::query()->where('wa_message_id', $id)->exists()) {
            return false;
        }
        DB::table('whatsapp_pending_statuses')->insert([
            'wa_message_id' => mb_substr($id, 0, 120), 'status' => mb_substr((string) ($st['status'] ?? ''), 0, 12),
            'payload' => json_encode($st), 'created_at' => now(),
        ]);
        $this->statuses->applyPending($id);

        return true;
    }

    private function once(string $key, string $kind, callable $work): void
    {
        DB::transaction(function () use ($key, $kind, $work) {
            $claimed = DB::table('whatsapp_inbound_events')->insertOrIgnore(['event_key' => $key, 'kind' => $kind, 'created_at' => now()]);
            if ($claimed) {
                $work();
            }
        });
    }

    private function status(array $st): void
    {
        $message = Message::query()->where('wa_message_id', $st['id'] ?? '')->first();
        if (! $message) {
            return;
        }
        $at = isset($st['timestamp']) ? Carbon::createFromTimestampUTC((int) $st['timestamp']) : now();

        match ($st['status'] ?? null) {
            'sent', 'delivered', 'read' => $this->statuses->advance($message, $st['status'], $at),
            'failed' => $this->statuses->fail(
                $message,
                (string) ($st['errors'][0]['code'] ?? 'failed'),
                (string) ($st['errors'][0]['error_data']['details'] ?? $st['errors'][0]['message'] ?? $st['errors'][0]['title'] ?? 'Delivery failed'),
                $at,
            ),
            default => null,
        };
    }

    private function templateStatus(array $value): void
    {
        $key = 'tpl:' . ($value['message_template_id'] ?? '') . ':' . ($value['event'] ?? '') . ':' . ($value['reason'] ?? '');
        $this->once($key . ':' . md5(json_encode($value)), 'template', function () use ($value) {
            $template = Template::query()->where('meta_name', $value['message_template_name'] ?? '')
                ->where('locale', str_starts_with((string) ($value['message_template_language'] ?? ''), 'en') ? 'en' : 'es')->first();
            if (! $template) {
                return;
            }
            $status = (new MetaGateway)->mapStatus((string) ($value['event'] ?? ''));
            $reason = ($value['reason'] ?? 'NONE') !== 'NONE' ? ($value['reason'] ?? null) : null;
            $template->forceFill([
                'status' => $status, 'rejection_reason' => $status === 'rejected' ? $reason : null,
                'approved_at' => $status === 'approved' ? ($template->approved_at ?? now()) : $template->approved_at,
            ])->save();
        });
    }

    private function inbound(array $msg): void
    {
        $from = '+' . ltrim((string) ($msg['from'] ?? ''), '+');
        $type = $msg['type'] ?? null;

        if ($type === 'text' && $this->isStop((string) ($msg['text']['body'] ?? ''))) {
            $this->optOut($from);

            return;
        }

        [$payload, $title] = match ($type) {
            'button' => [$msg['button']['payload'] ?? null, $msg['button']['text'] ?? null],
            'interactive' => [$msg['interactive']['button_reply']['id'] ?? null, $msg['interactive']['button_reply']['title'] ?? null],
            default => [null, null],
        };

        if ($payload === null && $title === null) {
            return;
        }

        if (is_string($payload) && preg_match('/^rsvp:(\d+):(yes|no)$/', $payload, $m)) {
            // The payload names the guest; the sender must be a number we sent that guest a message to.
            $guest = Guest::query()->find((int) $m[1]);
            if ($guest && Message::query()->where('guest_id', $guest->id)->where('to_phone', $from)->exists()) {
                $this->rsvp($guest, $m[2] === 'yes');
            } else {
                Log::info('whatsapp reply ignored: sender does not match the guest', ['from' => Phone::mask($from)]);
            }

            return;
        }

        // No usable payload: find the guest by phone; with more than one we cannot know who answered.
        $guestIds = Message::query()->where('to_phone', $from)->whereNotNull('guest_id')->distinct()->pluck('guest_id');
        $answer = $this->answerFromTitle((string) $title);
        if ($guestIds->count() === 1 && $answer !== null && ($guest = Guest::query()->find($guestIds->first()))) {
            $this->rsvp($guest, $answer);
        }
    }

    private function rsvp(Guest $guest, bool $attending): void
    {
        $event = Events::query()->find($guest->event_id);
        if (! $event || $guest->parent_id !== null) {
            return;
        }
        $status = $attending ? InvitationService::STATUS_ATTENDING : InvitationService::STATUS_NOT_ATTENDING;

        try {
            // The very same service the public invitation uses: window rules, status, date and rsvp log included.
            $this->invitations->saveRsvp($event, $guest, ['guest' => ['rsvpStatus' => $status]]);
        } catch (ValidationException) {
            Log::info('whatsapp rsvp ignored: responses are closed', ['event' => $event->id, 'guest' => $guest->id]);

            return;
        }

        $this->confirm($event, $guest, $status);
    }

    /** Session text (we are inside the 24 h window: the guest just wrote to us). */
    private function confirm(Events $event, Guest $guest, string $status): void
    {
        $to = Phone::normalize($guest->phone) ?? Message::query()->where('guest_id', $guest->id)->latest('id')->value('to_phone');
        if (! $to) {
            return;
        }
        $locale = $this->composer->localeFor($event, $guest);
        $template = Template::query()->where('key', 'rsvp_confirmation')->where('locale', $locale)->first();
        $params = $this->composer->params('rsvp_confirmation', $event, $guest, $locale, $status);
        $body = $template ? $this->composer->render($template, $params) : $params['status_text'];

        $message = Message::create([
            'event_id' => $event->id, 'guest_id' => $guest->id, 'template_key' => 'rsvp_confirmation', 'locale' => $locale, 'type' => 'text',
            'to_phone' => $to, 'params' => $params, 'body' => $body, 'status' => 'queued', 'queued_at' => now(),
        ]);
        SendWhatsAppMessage::dispatch($message->id)->afterCommit();
    }

    private function optOut(string $from): void
    {
        // The opt-out belongs to the number (audit W-02): stored even when we never messaged it, and for every guest with it.
        $e164 = Phone::normalize($from);
        if ($e164 === null) {
            return;
        }
        OptOut::add($e164);
        $tail = substr($e164, -7); // PostgreSQL only, like the rest of the app
        $byPhone = Guest::query()->whereRaw("regexp_replace(phone, '[^0-9]', '', 'g') like ?", ['%' . $tail])->get()->filter(fn (Guest $g) => Phone::normalize($g->phone) === $e164)->pluck('id');
        $ids = Message::query()->where('to_phone', $e164)->whereNotNull('guest_id')->distinct()->pluck('guest_id')->merge($byPhone)->unique();
        Guest::query()->whereIn('id', $ids)->whereNull('whatsapp_opted_out_at')->update(['whatsapp_opted_out_at' => now()]);
    }

    private function isStop(string $text): bool
    {
        $text = Str::lower(Str::ascii(trim($text)));
        $text = trim(preg_replace('/[^a-z ]+/', '', $text));

        return in_array(preg_replace('/\s+/', ' ', $text), self::STOP_WORDS, true);
    }

    private function answerFromTitle(string $title): ?bool
    {
        if ($title === '') {
            return null;
        }
        $buttons = Template::query()->whereIn('key', ['invitation', 'reminder_pending'])->pluck('buttons')->flatten(1);
        foreach ($buttons as $button) {
            if (Str::lower($button['text'] ?? '') === Str::lower($title)) {
                return $button['id'] === 'yes';
            }
        }

        return null;
    }
}
