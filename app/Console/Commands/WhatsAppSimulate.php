<?php

namespace App\Console\Commands;

use App\Http\Services\WhatsApp\WebhookProcessor;
use App\Http\Services\WhatsApp\WhatsAppService;
use App\Models\WhatsApp\Message;
use Illuminate\Console\Command;

/** Plays what WhatsApp would send back for a message, through the same code as the real webhook. Fake driver only. */
class WhatsAppSimulate extends Command
{
    protected $signature = 'whatsapp:simulate {messageId : id of a whatsapp_messages row} {action : delivered|read|failed|reply-yes|reply-no|stop}';

    protected $description = 'Simulate a WhatsApp delivery, read, failure, button reply or STOP (fake driver only)';

    public function handle(WhatsAppService $whatsapp, WebhookProcessor $processor): int
    {
        if ($whatsapp->driverName() !== 'fake') {
            $this->error('whatsapp:simulate only runs with WHATSAPP_DRIVER=fake.');

            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        if (! in_array($action, ['delivered', 'read', 'failed', 'reply-yes', 'reply-no', 'stop'], true)) {
            $this->error('Unknown action. Use delivered, read, failed, reply-yes, reply-no or stop.');

            return self::FAILURE;
        }

        $message = Message::query()->find($this->argument('messageId'));
        if (! $message) {
            $this->error('Message not found.');

            return self::FAILURE;
        }
        if (! $message->wa_message_id) {
            $this->error('That message has not been sent yet (no WhatsApp id).');

            return self::FAILURE;
        }

        $processor->process($this->payload($message, $action));
        $this->info("Simulated {$action} for message {$message->id}. Status now: " . $message->refresh()->status);

        return self::SUCCESS;
    }

    /** The body Meta would POST. @return array<string, mixed> */
    private function payload(Message $message, string $action): array
    {
        $from = ltrim($message->to_phone, '+');
        $uid = 'sim.' . bin2hex(random_bytes(6));
        $value = ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => 'fake']];

        if (in_array($action, ['delivered', 'read', 'failed'], true)) {
            $value['statuses'] = [array_filter([
                'id' => $message->wa_message_id, 'status' => $action, 'timestamp' => (string) now()->timestamp, 'recipient_id' => $from,
                'errors' => $action === 'failed' ? [['code' => 131026, 'title' => 'Message undeliverable', 'message' => 'Message undeliverable']] : null,
            ])];
        } elseif ($action === 'stop') {
            $value['messages'] = [['from' => $from, 'id' => $uid, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'STOP']]];
        } else {
            $answer = $action === 'reply-yes' ? 'yes' : 'no';
            $value['messages'] = [[
                'from' => $from, 'id' => $uid, 'timestamp' => (string) now()->timestamp, 'type' => 'button',
                'button' => ['payload' => "rsvp:{$message->guest_id}:{$answer}", 'text' => $answer],
            ]];
        }

        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'fake', 'changes' => [['field' => 'messages', 'value' => $value]]]]];
    }
}
