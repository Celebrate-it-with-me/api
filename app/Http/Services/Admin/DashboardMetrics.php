<?php

namespace App\Http\Services\Admin;

use App\Http\Services\AppServices\PlanFeatures;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\Guest;
use App\Models\User;
use App\Support\AdminPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the admin home (docs/plans/admin-brief.md, A11). One call, already calculated.
 * "Internal" accounts (staff and the @cwm.test demo ones) are left out unless asked for, so
 * our own tests do not look like customers.
 */
class DashboardMetrics
{
    private const OWNER_ROLE = 'owner';

    /** Staff and demo accounts. */
    private array $internalUserIds = [];

    public function build(int $days = 30, bool $includeInternal = false, bool $withMoney = true): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $now = CarbonImmutable::now();
        $from = $now->subDays($days);
        $prevFrom = $now->subDays($days * 2);

        $this->internalUserIds = $includeInternal ? [] : $this->internalUsers();

        $data = [
            'days' => $days,
            'includeInternal' => $includeInternal,
            'generatedAt' => $now->toIso8601String(),
            'kpis' => [
                'signups' => $this->pair(fn ($a, $b) => $this->users()->whereBetween('created_at', [$a, $b])->count(), $prevFrom, $from, $now),
                'eventsCreated' => $this->pair(fn ($a, $b) => $this->events()->whereBetween('events.created_at', [$a, $b])->count(), $prevFrom, $from, $now),
                'published' => $this->pair(fn ($a, $b) => $this->events()->whereBetween('published_at', [$a, $b])->count(), $prevFrom, $from, $now),
                'paid' => $this->pair(fn ($a, $b) => $this->purchases()->where('status', 'paid')->whereBetween('paid_at', [$a, $b])->count(), $prevFrom, $from, $now),
                'revenueCents' => $this->pair(fn ($a, $b) => (int) $this->purchases()->where('status', 'paid')->whereBetween('paid_at', [$a, $b])->sum('amount_cents'), $prevFrom, $from, $now),
                'refundedCents' => $this->pair(fn ($a, $b) => (int) $this->purchases()->where('status', 'refunded')->whereBetween('refunded_at', [$a, $b])->sum('amount_cents'), $prevFrom, $from, $now),
            ],
            'series' => $this->series($days, $now),
            'funnel' => $this->funnel($from, $now),
            'mix' => $this->mix(),
            'upcomingWeeks' => $this->upcomingWeeks($now),
            'usage' => $this->usage($now),
            'attention' => $this->attention($now),
        ];

        // Support does not see payments or amounts (owner's decision): the money numbers are left out, not zeroed.
        if (! $withMoney) {
            unset($data['kpis']['revenueCents'], $data['kpis']['refundedCents'], $data['series']['metrics']['revenueCents']);
        }

        return $data;
    }

    /** Value in the period and in the one before it, with the change. */
    private function pair(callable $count, CarbonImmutable $prevFrom, CarbonImmutable $from, CarbonImmutable $now): array
    {
        $current = $count($from, $now);
        $previous = $count($prevFrom, $from);

        return ['value' => $current, 'previous' => $previous, 'change' => $current - $previous];
    }

    /**
     * One value per day for each metric: the period (`current`) and the one before it (`previous`), same length,
     * so the chart can lay one over the other. Days with nothing are zeros, never gaps.
     */
    private function series(int $days, CarbonImmutable $now): array
    {
        $end = $now->startOfDay();
        $start = $end->subDays($days - 1);
        $prevStart = $start->subDays($days);
        $range = fn ($a, $b) => [$a->startOfDay(), $b->endOfDay()];

        $byDay = fn ($query, string $column, ?string $sum = null) => $query
            ->whereBetween($column, $range($prevStart, $end))
            ->selectRaw("DATE($column) as d, " . ($sum ? "COALESCE(SUM($sum), 0)" : 'COUNT(*)') . ' as v')
            ->groupBy('d')->pluck('v', 'd');

        $raw = [
            'signups' => $byDay($this->users(), 'users.created_at'),
            'eventsCreated' => $byDay($this->events(), 'events.created_at'),
            'published' => $byDay($this->events(), 'events.published_at'),
            'paid' => $byDay($this->purchases()->where('status', 'paid'), 'event_purchases.paid_at'),
            'revenueCents' => $byDay($this->purchases()->where('status', 'paid'), 'event_purchases.paid_at', 'amount_cents'),
        ];

        $out = ['dates' => [], 'metrics' => []];
        for ($i = 0; $i < $days; $i++) {
            $out['dates'][] = $start->addDays($i)->toDateString();
        }
        foreach ($raw as $key => $values) {
            $pick = fn ($from) => collect(range(0, $days - 1))->map(fn ($i) => (int) ($values[$from->addDays($i)->toDateString()] ?? 0))->all();
            $out['metrics'][$key] = ['current' => $pick($start), 'previous' => $pick($prevStart)];
        }

        return $out;
    }

    /** What the live events are made of: by type, plan and stage. */
    private function mix(): array
    {
        $count = fn ($query, string $column) => $query->selectRaw("$column as k, COUNT(*) as v")->groupBy('k')->orderByDesc('v')->pluck('v', 'k');

        $byType = DB::table('events')->join('event_types', 'event_types.id', '=', 'events.event_type_id')->whereNull('events.deleted_at')
            ->whereIn('events.id', $this->events()->select('events.id'));
        $byPlan = DB::table('events')->join('event_plans', 'event_plans.id', '=', 'events.event_plan_id')->whereNull('events.deleted_at')
            ->whereIn('events.id', $this->events()->select('events.id'));

        $stage = $this->events()->selectRaw("CASE WHEN status = 'archived' THEN 'archived' WHEN published_at IS NULL THEN 'draft' WHEN invitation_stage = 'save_the_date' THEN 'save_the_date' ELSE 'invitation' END as k, COUNT(*) as v")
            ->groupBy('k')->pluck('v', 'k');

        return [
            'type' => $count($byType, 'event_types.slug')->map(fn ($v, $k) => ['key' => $k, 'count' => (int) $v])->values()->all(),
            'plan' => $count($byPlan, 'event_plans.slug')->map(fn ($v, $k) => ['key' => $k, 'count' => (int) $v])->values()->all(),
            'stage' => collect(['draft', 'save_the_date', 'invitation', 'archived'])->map(fn ($k) => ['key' => $k, 'count' => (int) ($stage[$k] ?? 0)])->all(),
        ];
    }

    /** Events per week for the next eight weeks, split by published and still draft: the support workload ahead. */
    private function upcomingWeeks(CarbonImmutable $now): array
    {
        $first = $now->startOfWeek();
        $rows = $this->events()->where('status', '!=', 'archived')
            ->whereBetween('start_date', [$first, $first->addWeeks(8)->subSecond()])
            ->get(['start_date', 'published_at']);

        return collect(range(0, 7))->map(function ($i) use ($first, $rows) {
            $weekStart = $first->addWeeks($i);
            $inWeek = $rows->filter(fn ($e) => $e->start_date >= $weekStart && $e->start_date < $weekStart->addWeek());

            return [
                'weekStart' => $weekStart->toDateString(),
                'published' => $inWeek->whereNotNull('published_at')->count(),
                'draft' => $inWeek->whereNull('published_at')->count(),
            ];
        })->all();
    }

    /** Of the accounts that registered in the period, how many got to each step. */
    private function funnel(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $userIds = $this->users()->whereBetween('created_at', [$from, $now])->pluck('id');
        $owned = DB::table('event_user_roles')
            ->join('roles', 'roles.id', '=', 'event_user_roles.role_id')
            ->join('events', 'events.id', '=', 'event_user_roles.event_id')
            ->where('roles.name', self::OWNER_ROLE)
            ->whereNull('events.deleted_at')
            ->whereIn('event_user_roles.user_id', $userIds)
            ->select('event_user_roles.user_id', 'events.id as event_id', 'events.published_at');

        $rows = $owned->get();
        $eventIds = $rows->pluck('event_id');
        $withGuests = Guest::query()->whereIn('event_id', $eventIds)->distinct()->pluck('event_id')->flip();
        $paid = EventPurchase::query()->where('status', 'paid')->whereIn('event_id', $eventIds)->distinct()->pluck('event_id')->flip();

        $users = fn ($filter) => $rows->filter($filter)->pluck('user_id')->unique()->count();

        return [
            ['key' => 'signups', 'count' => $userIds->count()],
            ['key' => 'event', 'count' => $users(fn () => true)],
            ['key' => 'guests', 'count' => $users(fn ($r) => $withGuests->has($r->event_id))],
            ['key' => 'published', 'count' => $users(fn ($r) => $r->published_at !== null)],
            ['key' => 'paid', 'count' => $users(fn ($r) => $paid->has($r->event_id))],
        ];
    }

    private function usage(CarbonImmutable $now): array
    {
        $eventIds = $this->events()->pluck('events.id');
        $guests = Guest::query()->whereIn('event_id', $eventIds);
        $total = (clone $guests)->count();
        $answered = (clone $guests)->whereIn('rsvp_status', ['attending', 'not-attending'])->count();
        $upcoming = $this->events()->whereBetween('start_date', [$now, $now->addDays(30)]);

        return [
            'guests' => $total,
            'attending' => (clone $guests)->where('rsvp_status', 'attending')->count(),
            'answered' => $answered,
            'answerRate' => $total > 0 ? round($answered / $total * 100) : 0,
            'upcoming' => (clone $upcoming)->count(),
            'upcomingDraft' => (clone $upcoming)->where('status', 'draft')->count(),
            'liveEvents' => $this->events()->where('status', 'published')->count(),
        ];
    }

    /** Things somebody should look at today. Each has a total and up to five examples. */
    private function attention(CarbonImmutable $now): array
    {
        $items = [];
        $push = function (string $type, $query, callable $example) use (&$items) {
            $count = (clone $query)->count();
            if ($count > 0) {
                $items[] = ['type' => $type, 'count' => $count, 'examples' => (clone $query)->limit(5)->get()->map($example)->values()->all()];
            }
        };
        $event = fn ($e) => ['eventId' => $e->id, 'label' => $e->event_name];

        $push('stuck_purchases', $this->purchases()->whereIn('status', ['pending', 'failed'])->where('event_purchases.created_at', '<', $now->subHour())->with('event:id,event_name'),
            fn ($p) => ['eventId' => $p->event_id, 'label' => $p->event?->event_name, 'status' => $p->status]);

        $freeId = app(PlanFeatures::class)->entryPlanId();
        $limit = (int) EventPlan::query()->whereKey($freeId)->value('max_guests');
        $over = $this->events()->where('events.event_plan_id', $freeId)
            ->whereRaw('(select count(*) from guests where guests.event_id = events.id and guests.deleted_at is null) > ?', [$limit]);
        $push('over_free_limit', $over, $event);

        $push('upcoming_draft', $this->events()->where('status', 'draft')->whereBetween('start_date', [$now, $now->addDays(14)]), $event);

        $push('coupons_ending', Coupon::query()->where('is_active', true)->where(function ($q) use ($now) {
            $q->whereBetween('expires_at', [$now, $now->addDays(7)])
                ->orWhereRaw('max_uses is not null and times_used >= max_uses * 0.8 and times_used < max_uses');
        }), fn ($c) => ['label' => $c->code, 'used' => $c->times_used, 'max' => $c->max_uses]);

        return $items;
    }

    private function users()
    {
        return User::query()->when($this->internalUserIds, fn ($q) => $q->whereNotIn('users.id', $this->internalUserIds));
    }

    /** Events whose owner is not internal. */
    private function events()
    {
        return Events::query()->when($this->internalUserIds, fn ($q) => $q->whereNotIn('events.id', function ($sub) {
            $sub->select('event_user_roles.event_id')->from('event_user_roles')
                ->join('roles', 'roles.id', '=', 'event_user_roles.role_id')
                ->where('roles.name', self::OWNER_ROLE)
                ->whereIn('event_user_roles.user_id', $this->internalUserIds);
        }));
    }

    private function purchases()
    {
        return EventPurchase::query()->when($this->internalUserIds, fn ($q) => $q->whereNotIn('event_purchases.user_id', $this->internalUserIds));
    }

    /** Staff (role admin) and demo accounts. */
    private function internalUsers(): array
    {
        $staff = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', AdminPermissions::STAFF_ROLES)->pluck('model_has_roles.model_id');

        return User::query()->where('email', 'like', '%@cwm.test')->pluck('id')->merge($staff)->unique()->values()->all();
    }
}
