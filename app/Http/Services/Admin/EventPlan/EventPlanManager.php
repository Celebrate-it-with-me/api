<?php

namespace App\Http\Services\Admin\EventPlan;

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\AppServices\EventModules;
use App\Http\Services\AppServices\GuestLimit;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Plan and payment tab of the admin event sheet: the event's purchases and the manual plan change.
 * Marking a payment by hand and refunding already exist in AdminPaymentController and are not repeated here.
 */
class EventPlanManager
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly EventModules $modules,
        private readonly GuestLimit $limits,
    ) {}

    /** Purchases of the event, newest first. Gateway ids and receipt links are never exposed. */
    public function purchases(Events $event): array
    {
        $rows = EventPurchase::query()
            ->with(['plan:id,slug,name', 'coupon:id,code'])
            ->where('event_id', $event->id)->orderByDesc('id')->get();

        $people = User::query()->whereIn('id', $rows->pluck('user_id')->merge($rows->pluck('created_by'))->filter()->unique())
            ->get(['id', 'name'])->keyBy('id');

        $published = $event->status === 'published';

        return [
            'data' => $rows->map(fn (EventPurchase $p) => [
                'id' => $p->id,
                'status' => $p->status,
                'gateway' => $p->gateway,
                'plan' => $p->plan ? ['slug' => $p->plan->slug, 'name' => $p->plan->name] : null,
                'listPriceCents' => $p->list_price_cents,
                'amountCents' => $p->amount_cents,
                'currency' => $p->currency,
                'coupon' => $p->coupon?->code,
                'isFounder' => $p->is_founder,
                'notes' => $p->notes,
                'buyer' => isset($people[$p->user_id]) ? ['id' => $p->user_id, 'name' => $people[$p->user_id]->name] : null,
                'registeredBy' => isset($people[$p->created_by]) ? ['id' => $p->created_by, 'name' => $people[$p->created_by]->name] : null,
                'paidAt' => $p->paid_at?->toIso8601String(),
                'refundedAt' => $p->refunded_at?->toIso8601String(),
                'createdAt' => $p->created_at?->toIso8601String(),
                // Refunds are total and only while the invitation is not published.
                'refundable' => $p->status === 'paid' && ! $published,
                'refundBlockedReason' => match (true) {
                    $p->status !== 'paid' => 'not_paid',
                    $published => 'event_published',
                    default => null,
                },
            ])->values(),
            'eventStatus' => $event->status,
        ];
    }

    /** @return array{plan: array, warnings: list<array>, modulesLocked: list<string>, modulesUnlocked: list<string>} */
    public function changePlan(Events $event, string $planSlug, string $reason): array
    {
        $new = EventPlan::query()->where('slug', $planSlug)->firstOrFail();
        $old = $event->eventPlan;

        if ($old && $old->id === $new->id) {
            throw ValidationException::withMessages(['planSlug' => ['same_plan']]);
        }

        $before = $this->modules->forEvent($event);
        $guests = $this->limits->totalFor($event);

        $event->update(['event_plan_id' => $new->id]);
        $event->unsetRelation('eventPlan');

        $after = $this->modules->forEvent($event->fresh());
        $state = fn (array $list) => collect($list)->pluck('state', 'key');
        $was = $state($before);
        $now = $state($after);
        $locked = $now->filter(fn ($s, $k) => $s === 'locked' && ($was[$k] ?? null) === 'active')->keys()->values()->all();
        $unlocked = $now->filter(fn ($s, $k) => $s === 'active' && ($was[$k] ?? null) === 'locked')->keys()->values()->all();

        $warnings = [];
        $limit = (int) $new->max_guests;
        if ($limit > 0 && $guests > $limit) {
            // Nothing is deleted: the list stays, but publishing (or adding people once published) is blocked.
            $warnings[] = ['code' => 'guests_over_limit', 'limit' => $limit, 'guests' => $guests, 'published' => $event->status === 'published'];
        }
        if ($locked) {
            $warnings[] = ['code' => 'modules_locked', 'modules' => $locked];
        }
        $paid = EventPurchase::query()->where('event_id', $event->id)->where('status', 'paid')->exists();
        if ($paid && $new->slug === 'basic') {
            $warnings[] = ['code' => 'paid_purchase_exists'];
        }

        $this->audit->record('event.plan_change', $event, [
            'reason' => $reason,
            'before' => ['plan' => $old?->slug, 'guestLimit' => $old?->max_guests],
            'after' => ['plan' => $new->slug, 'guestLimit' => $new->max_guests, 'guests' => $guests, 'warnings' => array_column($warnings, 'code')],
        ]);

        return [
            'plan' => ['id' => $new->id, 'slug' => $new->slug, 'name' => $new->name, 'guestLimit' => $limit > 0 ? $limit : null],
            'warnings' => $warnings,
            'modulesLocked' => $locked,
            'modulesUnlocked' => $unlocked,
        ];
    }
}
