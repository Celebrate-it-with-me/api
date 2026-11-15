<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;

/** An event received from WhatsApp, kept by key so the same delivery is never processed twice. */
class InboundEvent extends Model
{
    public $timestamps = false;

    protected $table = 'whatsapp_inbound_events';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];
}
