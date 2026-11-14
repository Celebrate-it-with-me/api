<?php

namespace App\Models\WhatsApp;

use App\Models\Events;
use App\Models\Guest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One WhatsApp message we send (a template, or a session text reply). Status only moves forward: queued, sent, delivered, read; or failed. */
class Message extends Model
{
    public const STATUSES = ['queued', 'sent', 'delivered', 'read', 'failed'];

    /** Position of each status on the way forward (failed is handled apart). */
    public const RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    protected $table = 'whatsapp_messages';

    protected $guarded = ['id'];

    protected $casts = [
        'params' => 'array', 'queued_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime',
        'read_at' => 'datetime', 'failed_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id')->withTrashed();
    }
}
