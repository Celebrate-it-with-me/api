<?php

namespace App\Jobs;

use App\Http\Services\WhatsApp\MessageComposer;
use App\Http\Services\WhatsApp\MessageStatus;
use App\Http\Services\WhatsApp\WhatsAppException;
use App\Http\Services\WhatsApp\WhatsAppService;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\OptOut;
use App\Support\WhatsApp\Phone;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/** Sends one queued WhatsApp message. Temporary errors are retried with backoff; the rest mark the message failed with its code. */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public int $messageId)
    {
        $this->onQueue('whatsapp');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // Meta allows ~80 messages per second on the default tier; stay well under it. The sync driver has nowhere to wait.
        if (config('queue.default') === 'sync') {
            return [];
        }
        RateLimiter::for('whatsapp', fn () => Limit::perSecond((int) config('services.whatsapp.rate_per_second', 30)));

        return [(new RateLimited('whatsapp'))->releaseAfter(2)];
    }

    public function handle(WhatsAppService $whatsapp, MessageComposer $composer, MessageStatus $status): void
    {
        $message = Message::query()->with('guest')->find($this->messageId);

        // Already sent or failed (a retry of the job, or a double dispatch): nothing to do.
        if (! $message || $message->status !== 'queued') {
            return;
        }

        if ($message->type === 'template' && ($message->guest?->whatsapp_opted_out_at || OptOut::has($message->to_phone))) {
            $status->fail($message, 'opted_out', 'The guest opted out of WhatsApp messages.');

            return;
        }

        // Hand the message over once (audit W-06): the Cloud API has no idempotency key for sends, so only the worker that
        // wins this claim talks to Meta, and a send whose outcome we never learned is never repeated automatically.
        $claimed = Message::query()->whereKey($message->id)->where('status', 'queued')->whereNull('send_started_at')->update(['send_started_at' => now()]);
        if (! $claimed) {
            return;
        }

        try {
            $gateway = $whatsapp->gateway();
            if ($message->type === 'text') {
                $id = $gateway->sendText($message->to_phone, (string) $message->body);
            } else {
                $template = $whatsapp->sendable($message->template_key, $message->locale);
                if (! $template) {
                    $status->fail($message, 'template_not_approved', 'The WhatsApp template is not approved.');

                    return;
                }
                $payloads = collect($template->buttons)->map(fn (array $b) => 'rsvp:' . ($message->guest_id ?? 0) . ':' . $b['id'])->all();
                $id = $gateway->sendTemplate($message->to_phone, $template->meta_name, $message->locale, $composer->positional($template, $message->params ?? []), $payloads);
            }
        } catch (WhatsAppException $e) {
            Log::warning('whatsapp send failed', ['message' => $message->id, 'to' => Phone::mask($message->to_phone), 'code' => $e->errorCode, 'retryable' => $e->retryable]);

            // The outcome is unknown (timeout, 5xx): Meta may have delivered it. Never resend by itself.
            if ($e->ambiguous) {
                $status->fail($message, 'unknown_delivery', 'WhatsApp did not confirm whether the message was delivered. Check before retrying.');

                return;
            }
            // Rejected for sure (throttled...): nothing was sent, so it can be handed over again.
            if ($e->retryable && $this->attempts() < $this->tries && config('queue.default') !== 'sync') {
                Message::query()->whereKey($message->id)->update(['send_started_at' => null]);
                $this->release($this->backoff[$this->attempts() - 1] ?? 900);

                return;
            }
            $status->fail($message, $e->errorCode, $e->getMessage());

            return;
        }

        $status->sent($message, $id);
    }

    /** Last resort: an unexpected exception exhausted the attempts. */
    public function failed(\Throwable $e): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message) {
            app(MessageStatus::class)->fail($message, 'internal_error', 'The message could not be sent.');
        }
    }
}
