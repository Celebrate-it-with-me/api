<?php

namespace App\Http\Services\Admin\Events;

use App\Http\Services\AppServices\EventModules;
use App\Models\EventActivity;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the admin event manager (docs/plans/admin-brief.md, A2): the list and the summary of one event.
 * Related data is loaded in batches for the whole page, so the query count does not depend on the number of rows.
 */
class EventDirectory
{
    private const OWNER_ROLE = 'owner';

    public function __construct(private readonly EventModules $modules) {}

    /** @param array<string, mixed> $f validated filters from EventIndexRequest */
    public function list(array $f): array
    {
        $perPage = (int) ($f['perPage'] ?? 15);
        $query = Events::query()->with(['eventType:id,name,slug', 'eventPlan:id,name,slug,max_guests']);

        $this->applyFilters($query, $f);

        if (($f['sort'] ?? 'createdAt') === 'startDate') {
            $query->orderBy('events.start_date')->orderByDesc('events.id');
        } else {
            $query->orderByDesc('events.created_at')->orderByDesc('events.id');
        }

        $page = $query->paginate($perPage, ['events.*'], 'page', (int) ($f['page'] ?? 1));
        $ids = $page->getCollection()->pluck('id');

        $owners = $this->owners($ids->all());
        $guestCounts = DB::table('guests')->whereIn('event_id', $ids)->whereNull('deleted_at')
            ->selectRaw('event_id, COUNT(*) as total')->groupBy('event_id')->pluck('total', 'event_id');
        $lastPurchases = $this->lastPurchases($ids->all());

        $rows = $page->getCollection()->map(fn (Events $e) => [
            'id' => $e->id,
            'name' => $e->event_name,
            'slug' => $e->custom_url_slug,
            'type' => $e->eventType ? ['slug' => $e->eventType->slug, 'name' => $e->eventType->name] : null,
            'plan' => $e->eventPlan ? ['slug' => $e->eventPlan->slug, 'name' => $e->eventPlan->name] : null,
            'status' => $e->status,
            'stage' => $e->invitation_stage,
            'startDate' => $e->start_date?->toIso8601String(),
            'owner' => $owners[$e->id] ?? null,
            'guestCount' => (int) ($guestCounts[$e->id] ?? 0),
            'lastPurchaseStatus' => $lastPurchases[$e->id] ?? null,
            'createdAt' => $e->created_at?->toIso8601String(),
        ])->values();

        return [
            'data' => $rows,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
        ];
    }

    public function summary(Events $event, bool $withPayments = false): array
    {
        $event->load(['eventType:id,name,slug', 'eventPlan']);

        $team = DB::table('event_user_roles')
            ->join('roles', 'roles.id', '=', 'event_user_roles.role_id')
            ->join('users', 'users.id', '=', 'event_user_roles.user_id')
            ->where('event_user_roles.event_id', $event->id)
            ->orderBy('event_user_roles.id')
            ->get(['users.id', 'users.name', 'users.email', 'roles.name as role']);

        $owner = $team->firstWhere('role', self::OWNER_ROLE);

        $purchases = EventPurchase::query()->with('plan:id,slug,name')->where('event_id', $event->id)
            ->orderByDesc('id')->limit(5)->get()
            ->map(fn (EventPurchase $p) => [
                'id' => $p->id,
                'status' => $p->status,
                'gateway' => $p->gateway,
                'plan' => $p->plan?->slug,
                'listPriceCents' => $p->list_price_cents,
                'amountCents' => $p->amount_cents,
                'currency' => $p->currency,
                'isFounder' => $p->is_founder,
                'paidAt' => $p->paid_at?->toIso8601String(),
                'refundedAt' => $p->refunded_at?->toIso8601String(),
                'createdAt' => $p->created_at?->toIso8601String(),
            ])->values();

        $front = rtrim((string) config('app.frontend_app.url'), '/');
        $slug = $event->custom_url_slug;

        return [
            'event' => [
                'id' => $event->id,
                'name' => $event->event_name,
                'description' => $event->event_description,
                'slug' => $slug,
                'slugLocked' => $event->isSlugLocked(),
                'type' => $event->eventType ? ['id' => $event->eventType->id, 'slug' => $event->eventType->slug, 'name' => $event->eventType->name] : null,
                'status' => $event->status,
                'stage' => $event->invitation_stage,
                'startDate' => $event->start_date?->toIso8601String(),
                'endDate' => $event->end_date?->toIso8601String(),
                'publishedAt' => $event->published_at?->toIso8601String(),
                'locale' => $event->locale,
                'timezone' => $event->timezone,
                'currency' => $event->currency,
                'createdAt' => $event->created_at?->toIso8601String(),
                'updatedAt' => $event->updated_at?->toIso8601String(),
                'deletedAt' => $event->deleted_at?->toIso8601String(),
            ],
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
            'collaborators' => $team->where('role', '!=', self::OWNER_ROLE)
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role])->values(),
            'plan' => $event->eventPlan ? [
                'id' => $event->eventPlan->id,
                'slug' => $event->eventPlan->slug,
                'name' => $event->eventPlan->name,
                'guestLimit' => $event->eventPlan->max_guests,
                'priceCents' => $withPayments ? $event->eventPlan->price_cents : null,
            ] : null,
            'payment' => $withPayments ? [
                'lastPurchase' => $purchases->first(),
                'purchases' => $purchases,
            ] : null,
            'publicLink' => [
                'isLive' => $event->status === 'published' && $slug !== null,
                // The real invitation route is /i/{slug}/{guestCode}: one link per main guest. Guest codes are not exposed here.
                'url' => $slug ? "$front/i/" . rawurlencode($slug) : null,
                'pattern' => $slug ? "$front/i/" . rawurlencode($slug) . '/{code}' : null,
            ],
            'counts' => $this->counts($event),
            'modules' => collect($this->modules->forEvent($event))
                ->map(fn ($m) => ['key' => $m['key'], 'section' => $m['section'], 'state' => $m['state']])->values(),
            'recentActivity' => $this->activity($event),
        ];
    }

    private function counts(Events $event): array
    {
        $byStatus = DB::table('guests')->where('event_id', $event->id)->whereNull('deleted_at')
            ->selectRaw('rsvp_status, COUNT(*) as total')->groupBy('rsvp_status')->pluck('total', 'rsvp_status');
        $total = (int) $byStatus->sum();
        $attending = (int) ($byStatus['attending'] ?? 0);
        $declined = (int) ($byStatus['not-attending'] ?? 0);

        $budget = DB::table('event_budgets')->where('event_id', $event->id)->first(['id', 'budget_cap']);
        $expenses = null;
        if ($budget) {
            $items = DB::table('budget_items')->where('event_budget_id', $budget->id)
                ->selectRaw('COALESCE(SUM(COALESCE(agreed_cost, estimated_cost)), 0) as owed, COUNT(*) as items')->first();
            $paid = DB::table('budget_payments')->join('budget_items', 'budget_items.id', '=', 'budget_payments.budget_item_id')
                ->where('budget_items.event_budget_id', $budget->id)->sum('budget_payments.amount');
            $expenses = [
                'items' => (int) $items->items,
                'total' => round((float) $items->owed, 2),
                'paid' => round((float) $paid, 2),
                'cap' => $budget->budget_cap !== null ? (float) $budget->budget_cap : null,
                'currency' => $event->currency,
            ];
        }

        return [
            'guests' => $total,
            'attending' => $attending,
            'notAttending' => $declined,
            'pending' => $total - $attending - $declined,
            'expenses' => $expenses,
        ];
    }

    private function activity(Events $event): array
    {
        return EventActivity::query()->with('actor')->where('event_id', $event->id)
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn (EventActivity $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'message' => $a->message,
                'actorName' => $a->actor?->name,
                'createdAt' => $a->created_at?->toIso8601String(),
            ])->values()->all();
    }

    private function applyFilters(Builder $query, array $f): void
    {
        if (filled($f['search'] ?? null)) {
            $like = '%' . strtolower(addcslashes($f['search'], '\\%_')) . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw("lower(events.event_name) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(events.custom_url_slug) like ? escape '\\'", [$like])
                    ->orWhereIn('events.id', $this->ownedBy(fn ($u) => $u
                        ->where(fn ($w) => $w->whereRaw("lower(users.name) like ? escape '\\'", [$like])->orWhereRaw("lower(users.email) like ? escape '\\'", [$like]))));
            });
        }
        if (filled($f['status'] ?? null)) {
            $query->where('events.status', $f['status']);
        }
        if (filled($f['stage'] ?? null)) {
            $query->where('events.invitation_stage', $f['stage']);
        }
        if (filled($f['plan'] ?? null)) {
            $query->whereIn('events.event_plan_id', DB::table('event_plans')->where('slug', $f['plan'])->select('id'));
        }
        if (filled($f['type'] ?? null)) {
            $query->whereIn('events.event_type_id', DB::table('event_types')->where('slug', $f['type'])->select('id'));
        }
        if (filled($f['from'] ?? null)) {
            $query->where('events.start_date', '>=', $f['from'] . ' 00:00:00');
        }
        if (filled($f['to'] ?? null)) {
            $query->where('events.start_date', '<=', $f['to'] . ' 23:59:59');
        }
        if (! empty($f['paymentPending'])) {
            $query->whereIn('events.id', DB::table('event_purchases')->whereIn('status', ['pending', 'failed'])->select('event_id'));
        }
        if (empty($f['internal'])) {
            $internal = $this->internalUserIds();
            if ($internal) {
                $query->whereNotIn('events.id', $this->ownedBy(fn ($u) => $u->whereIn('users.id', $internal)));
            }
        }
    }

    /** Ids of events whose owner matches the constraint on the users table. */
    private function ownedBy(callable $constrain)
    {
        $sub = DB::table('event_user_roles')
            ->join('roles', 'roles.id', '=', 'event_user_roles.role_id')
            ->join('users', 'users.id', '=', 'event_user_roles.user_id')
            ->where('roles.name', self::OWNER_ROLE);
        $constrain($sub);

        return $sub->select('event_user_roles.event_id');
    }

    /** @return array<int, array{id:int,name:string,email:string}> by event id */
    private function owners(array $eventIds): array
    {
        return DB::table('event_user_roles')
            ->join('roles', 'roles.id', '=', 'event_user_roles.role_id')
            ->join('users', 'users.id', '=', 'event_user_roles.user_id')
            ->where('roles.name', self::OWNER_ROLE)->whereIn('event_user_roles.event_id', $eventIds)
            ->orderByDesc('event_user_roles.id')
            ->get(['event_user_roles.event_id', 'users.id', 'users.name', 'users.email'])
            ->mapWithKeys(fn ($r) => [$r->event_id => ['id' => $r->id, 'name' => $r->name, 'email' => $r->email]])
            ->all();
    }

    /** @return array<int, string> status of the latest purchase, by event id */
    private function lastPurchases(array $eventIds): array
    {
        return DB::table('event_purchases')->whereIn('event_id', $eventIds)->orderBy('id')
            ->get(['event_id', 'status'])->mapWithKeys(fn ($r) => [$r->event_id => $r->status])->all();
    }

    /** Staff and demo accounts, same rule as DashboardMetrics. */
    private function internalUserIds(): array
    {
        $staff = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', AdminPermissions::STAFF_ROLES)->pluck('model_has_roles.model_id');

        return User::query()->where('email', 'like', '%@cwm.test')->pluck('id')->merge($staff)->unique()->values()->all();
    }
}
