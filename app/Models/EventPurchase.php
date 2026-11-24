<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventPurchase extends Model
{
    protected $fillable = [
        'event_id', 'user_id', 'event_plan_id', 'coupon_id', 'launch_offer_id', 'gateway', 'gateway_session_id', 'gateway_payment_id',
        'list_price_cents', 'amount_cents', 'currency', 'status', 'is_founder', 'receipt_url', 'paid_at',
        'refunded_at', 'notes', 'created_by', 'gateway_refund_id', 'refund_reason', 'failure_code', 'session_expires_at',
        'tax_cents', 'dispute_id', 'dispute_status', 'disputed_at', 'needs_review', 'chargeback_at',
    ];

    protected $casts = [
        'is_founder' => 'boolean', 'needs_review' => 'boolean', 'paid_at' => 'datetime', 'refunded_at' => 'datetime',
        'session_expires_at' => 'datetime', 'disputed_at' => 'datetime', 'chargeback_at' => 'datetime',
    ];

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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'purchase_id');
    }
}
