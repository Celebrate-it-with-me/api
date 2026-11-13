<?php

namespace App\Http\Services\Admin\Users;

use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Support\AdminPermissions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/** Reads and the destructive operations of the admin Users module (A5). */
class AdminUserService
{
    public function paginate(array $f): LengthAwarePaginator
    {
        $staff = AdminPermissions::STAFF_ROLES;
        $kind = $f['kind'] ?? null;
        $internal = filter_var($f['internal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $like = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return User::query()
            ->with('roles:id,name')
            ->withCount('eventRoles as events_count')
            ->addSelect([
                'users.*',
                'last_access_at' => UserLoginSession::query()->select('login_time')->whereColumn('user_id', 'users.id')->orderByDesc('login_time')->limit(1),
            ])
            ->when($f['search'] ?? null, function (Builder $q, string $s) use ($like) {
                $s = '%' . addcslashes($s, '%_\\') . '%';
                $q->where(fn (Builder $w) => $w->where('name', $like, $s)->orWhere('email', $like, $s)->orWhere('phone', $like, $s));
            })
            ->when($kind === 'staff', fn (Builder $q) => $q->role($staff))
            ->when($kind === 'customer', fn (Builder $q) => $q->whereDoesntHave('roles', fn (Builder $r) => $r->whereIn('name', $staff)))
            ->when(! $internal && $kind !== 'staff', fn (Builder $q) => $q
                ->where('email', 'not like', '%@cwm.test')
                ->when($kind === null, fn (Builder $w) => $w->whereDoesntHave('roles', fn (Builder $r) => $r->whereIn('name', $staff))))
            ->when(($f['status'] ?? null) === 'suspended', fn (Builder $q) => $q->whereNotNull('suspended_at'))
            ->when(($f['status'] ?? null) === 'active', fn (Builder $q) => $q->whereNull('suspended_at'))
            ->orderByDesc('users.id')
            ->paginate($f['perPage'] ?? 25, ['*'], 'page', $f['page'] ?? 1);
    }

    public function row(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'staffRoles' => $u->roles->pluck('name')->intersect(AdminPermissions::STAFF_ROLES)->values(),
            'eventsCount' => (int) ($u->events_count ?? $u->eventRoles()->count()),
            'suspended' => $u->suspended_at !== null,
            'anonymized' => $u->anonymized_at !== null,
            'lastAccessAt' => $u->last_access_at ? Carbon::parse($u->last_access_at)->toIso8601String() : null,
            'createdAt' => $u->created_at?->toIso8601String(),
        ];
    }

    public function profile(User $user): array
    {
        $user->load('roles:id,name');

        $events = EventUserRole::query()
            ->where('user_id', $user->id)
            ->with(['role:id,name', 'event' => fn ($q) => $q->select('id', 'event_name', 'status', 'event_plan_id', 'published_at', 'custom_url_slug')->with('eventPlan:id,name,slug')])
            ->get()
            ->filter(fn (EventUserRole $r) => $r->event !== null)
            ->map(fn (EventUserRole $r) => [
                'id' => $r->event->id,
                'name' => $r->event->event_name,
                'slug' => $r->event->custom_url_slug,
                'role' => $r->role?->name,
                'status' => $r->event->status,
                'published' => $r->event->published_at !== null,
                'plan' => $r->event->eventPlan ? ['slug' => $r->event->eventPlan->slug, 'name' => $r->event->eventPlan->name] : null,
            ])->values();

        $paid = EventPurchase::query()->where('user_id', $user->id)->where('status', 'paid');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatarUrl' => $user->avatar_url ?: null,
            'emailVerified' => $user->email_verified_at !== null,
            'staffRoles' => $user->roles->pluck('name')->intersect(AdminPermissions::STAFF_ROLES)->values(),
            'createdAt' => $user->created_at?->toIso8601String(),
            'anonymizedAt' => $user->anonymized_at?->toIso8601String(),
            'suspension' => $user->suspended_at ? [
                'at' => $user->suspended_at->toIso8601String(),
                'by' => $user->suspended_by ? User::query()->whereKey($user->suspended_by)->first(['id', 'name', 'email'])?->only(['id', 'name', 'email']) : null,
                'reason' => $user->suspension_reason,
            ] : null,
            'events' => $events,
            'purchases' => [
                'paidCount' => (clone $paid)->count(),
                'paidTotalCents' => (int) (clone $paid)->sum('amount_cents'),
                'refundedCount' => EventPurchase::query()->where('user_id', $user->id)->where('status', 'refunded')->count(),
                'currency' => EventPurchase::query()->where('user_id', $user->id)->latest('id')->value('currency') ?? 'USD',
            ],
            'recentSessions' => $user->userLoginSessions()->latest('login_time')->limit(10)->get()
                ->map(fn (UserLoginSession $s) => [
                    'loginTime' => $s->login_time ? Carbon::parse($s->login_time)->toIso8601String() : null,
                    'logoutTime' => $s->logout_time ? Carbon::parse($s->logout_time)->toIso8601String() : null,
                    'ip' => $s->ip_address,
                    'browser' => $s->browser,
                    'device' => $s->device,
                    'location' => $s->location,
                ])->values(),
        ];
    }

    public function suspend(User $user, User $actor, string $reason): void
    {
        DB::transaction(function () use ($user, $actor, $reason) {
            $user->forceFill(['suspended_at' => now(), 'suspended_by' => $actor->id, 'suspension_reason' => $reason])->save();
            $user->tokens()->delete();
            app(\App\Http\Services\Admin\Impersonation\ImpersonationService::class)->finishForUser($user);
        });
    }

    public function unsuspend(User $user): void
    {
        $user->forceFill(['suspended_at' => null, 'suspended_by' => null, 'suspension_reason' => null])->save();
    }

    /** Personal data the user can ask for. Never includes payment gateway identifiers. */
    public function export(User $user): array
    {
        $roles = EventUserRole::query()->where('user_id', $user->id)->with(['role:id,name', 'event'])->get()->filter(fn ($r) => $r->event);

        return [
            'exportedAt' => now()->toIso8601String(),
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                'avatar' => $user->avatar, 'emailVerifiedAt' => $user->email_verified_at?->toIso8601String(),
                'createdAt' => $user->created_at?->toIso8601String(),
            ],
            'events' => $roles->map(fn (EventUserRole $r) => [
                'id' => $r->event->id, 'name' => $r->event->event_name, 'role' => $r->role?->name,
                'status' => $r->event->status, 'startDate' => $r->event->start_date, 'slug' => $r->event->custom_url_slug,
                'guests' => $r->role?->name === 'owner'
                    ? Guest::query()->where('event_id', $r->event->id)->get(['id', 'parent_id', 'name', 'email', 'phone', 'rsvp_status', 'code'])->map->toArray()->all()
                    : [],
            ])->values()->all(),
            'purchases' => EventPurchase::query()->where('user_id', $user->id)->get()
                ->map(fn (EventPurchase $p) => [
                    'id' => $p->id, 'eventId' => $p->event_id, 'amountCents' => $p->amount_cents, 'currency' => $p->currency,
                    'status' => $p->status, 'gateway' => $p->gateway, 'paidAt' => $p->paid_at?->toIso8601String(),
                    'refundedAt' => $p->refunded_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * Anonymise instead of deleting: purchases keep their owner row. Events the user alone owns are archived.
     *
     * @return array{archivedEvents: list<int>}
     */
    public function anonymize(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
            $owned = EventUserRole::query()->where('user_id', $user->id)->where('role_id', $ownerRoleId)->pluck('event_id');
            $soleOwned = $owned->filter(fn ($eventId) => ! EventUserRole::query()
                ->where('event_id', $eventId)->where('role_id', $ownerRoleId)->where('user_id', '!=', $user->id)->exists())->values();

            Events::query()->whereIn('id', $soleOwned)->where('status', '!=', 'archived')->update(['status' => 'archived']);

            $user->tokens()->delete();
            app(\App\Http\Services\Admin\Impersonation\ImpersonationService::class)->finishForUser($user);
            $user->socialAccounts()->delete();
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted-{$user->id}@deleted.invalid",
                'phone' => null,
                'avatar' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'email_verified_at' => null,
                'anonymized_at' => now(),
            ])->save();

            return ['archivedEvents' => $soleOwned->all()];
        });
    }
}
