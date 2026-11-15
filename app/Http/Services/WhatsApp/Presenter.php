<?php

namespace App\Http\Services\WhatsApp;

use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\Template;
use App\Support\WhatsApp\Phone;

/** The `Message` and `Template` resources of docs/plans/communications-contract.md (camelCase, ISO 8601 UTC). */
class Presenter
{
    /** @param bool $mask hide the middle of the number (admin); the organizer sees her own guests' numbers in full */
    public static function message(Message $m, bool $mask = false): array
    {
        return [
            'id' => $m->id,
            'eventId' => $m->event_id,
            'eventName' => $m->event?->event_name,
            'guestId' => $m->guest_id,
            'guestName' => $m->guest?->name,
            'templateKey' => $m->template_key,
            'locale' => $m->locale,
            'to' => $mask ? Phone::mask($m->to_phone) : $m->to_phone,
            'status' => $m->status,
            'errorCode' => $m->error_code,
            'errorMessage' => $m->error_message,
            'waMessageId' => $m->wa_message_id,
            'queuedAt' => self::date($m->queued_at),
            'sentAt' => self::date($m->sent_at),
            'deliveredAt' => self::date($m->delivered_at),
            'readAt' => self::date($m->read_at),
            'failedAt' => self::date($m->failed_at),
        ];
    }

    public static function template(Template $t): array
    {
        return [
            'id' => $t->id, 'key' => $t->key, 'locale' => $t->locale, 'metaName' => $t->meta_name, 'category' => $t->category,
            'body' => $t->body, 'buttons' => $t->buttons ?? [], 'variables' => $t->variables ?? [], 'status' => $t->status,
            'rejectionReason' => $t->rejection_reason, 'submittedAt' => self::date($t->submitted_at), 'approvedAt' => self::date($t->approved_at),
            'updatedAt' => self::date($t->updated_at),
        ];
    }

    private static function date($value): ?string
    {
        return $value?->copy()->utc()->toIso8601String();
    }
}
