<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPurchase extends Model
{
    protected $fillable = [
        'event_id', 'user_id', 'event_plan_id', 'coupon_id', 'gateway', 'gateway_session_id', 'gateway_payment_id',
        'list_price_cents', 'amount_cents', 'currency', 'status', 'is_founder', 'receipt_url', 'paid_at',
        'refunded_at', 'notes', 'created_by',
    ];

    protected $casts = ['is_founder' => 'boolean', 'paid_at' => 'datetime', 'refunded_at' => 'datetime'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(EventPlan::class, 'event_plan_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The admin who recorded a manual payment. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
