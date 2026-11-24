<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Launch offer: a reduced price for the first `total_slots` paid events, no code needed. A slot is consumed when a purchase
 * made with the offer is PAID and is freed when it is refunded, charged back or never completes; the number used is always
 * derived from the purchases, never stored.
 */
class LaunchOffer extends Model
{
    /** How long a pending checkout holds a slot (same as a capped coupon), so the last slots are not oversold at checkout. */
    public const HOLD_MINUTES = 60;

    protected $fillable = ['event_plan_id', 'is_active', 'price_cents', 'total_slots', 'used_adjustment'];

    protected $casts = ['is_active' => 'boolean'];

    public function purchases(): HasMany
    {
        return $this->hasMany(EventPurchase::class);
    }

    public function paidCount(): int
    {
        return $this->purchases()->where('status', 'paid')->count();
    }

    /** Slots consumed: paid offer purchases plus the admin correction (never below zero). */
    public function usedSlots(): int
    {
        return max(0, $this->paidCount() + (int) $this->used_adjustment);
    }

    /** Slots held by pending checkouts of OTHER events (an event's own pending checkout is replaceable, so it holds nothing against it). */
    public function heldSlots(?int $exceptEventId = null): int
    {
        return $this->purchases()->where('status', 'pending')
            ->where('created_at', '>', now()->subMinutes(self::HOLD_MINUTES))
            ->when($exceptEventId, fn ($q) => $q->where('event_id', '!=', $exceptEventId))
            ->count();
    }

    /** Slots this account already holds with pending checkouts of OTHER events. */
    public function heldByUser(int $userId, ?int $exceptEventId = null): int
    {
        return $this->purchases()->where('status', 'pending')->where('user_id', $userId)
            ->where('created_at', '>', now()->subMinutes(self::HOLD_MINUTES))
            ->when($exceptEventId, fn ($q) => $q->where('event_id', '!=', $exceptEventId))
            ->count();
    }

    public function slotsLeft(?int $exceptEventId = null): int
    {
        return max(0, (int) $this->total_slots - $this->usedSlots() - $this->heldSlots($exceptEventId));
    }

    /** On, cheaper than the list price, with a slot left for this event, and (given a buyer) she is under her hold cap. */
    public function isOpen(EventPlan $plan, ?int $forEventId = null, ?int $forUserId = null): bool
    {
        return $this->is_active && (int) $this->price_cents < (int) $plan->price_cents && $this->slotsLeft($forEventId) > 0
            && ($forUserId === null || $this->heldByUser($forUserId, $forEventId) < max(1, (int) config('services.payments.offer_max_holds_per_user', 2)));
    }
}
