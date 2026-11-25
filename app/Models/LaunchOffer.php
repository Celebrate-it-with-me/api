<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Launch offer: a reduced price for the first `total_slots` paid events, no code needed. A slot is consumed when a purchase
 * made with the offer is PAID and is freed when it is refunded, charged back or never completes; the number used is always
 * derived from the purchases, never stored.
 *
 * LO-005 (decided, rule unchanged): a refund (only allowed while the invitation is unpublished) or a lost chargeback frees the slot,
 * and the same buyer may buy again at the offer price while slots remain. She pays the offer price each time and the platform
 * refunds in full, so nothing is gained; the slot returns to the pool for others. Penalising it (keeping the slot after a refund)
 * is a product decision, not made here.
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

    /** Why the offer does not apply (see {@see self::closedReason()}); the values are a closed list the screens can map to a text. */
    public const REASONS = ['inactive', 'closed', 'exhausted', 'holds_full'];

    /**
     * Why the offer does not apply to this event and buyer, or null when it does. Bounded values, never ids or other buyers' data:
     * `inactive` (switched off), `closed` (not cheaper than the list price any more), `exhausted` (no slot left, counting holds)
     * and `holds_full` (slots remain but this account already holds her maximum with other events' open payments).
     */
    public function closedReason(EventPlan $plan, ?int $forEventId = null, ?int $forUserId = null): ?string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            (int) $this->price_cents >= (int) $plan->price_cents => 'closed',
            $this->slotsLeft($forEventId) <= 0 => 'exhausted',
            $forUserId !== null && $this->heldByUser($forUserId, $forEventId) >= max(1, (int) config('services.payments.offer_max_holds_per_user', 2)) => 'holds_full',
            default => null,
        };
    }

    /** On, cheaper than the list price, with a slot left for this event, and (given a buyer) she is under her hold cap. */
    public function isOpen(EventPlan $plan, ?int $forEventId = null, ?int $forUserId = null): bool
    {
        return $this->closedReason($plan, $forEventId, $forUserId) === null;
    }
}
