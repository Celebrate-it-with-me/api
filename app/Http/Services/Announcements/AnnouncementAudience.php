<?php

namespace App\Http\Services\Announcements;

use App\Models\EventPurchase;
use App\Models\EventUserRole;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Builder;

/** Who an announcement is for. The organizers are the users without a staff role. */
class AnnouncementAudience
{
    public function users(string $audience, ?int $upcomingDays = null): Builder
    {
        $query = User::query()->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', AdminPermissions::STAFF_ROLES));

        // A paid purchase is status `paid` (a refund moves it to `refunded`); the buyer is `user_id`.
        $paid = fn () => EventPurchase::query()->where('status', 'paid')->select('user_id');

        return match ($audience) {
            'free' => $query->whereNotIn('id', $paid()),
            'paid' => $query->whereIn('id', $paid()),
            'upcoming_event' => $query->whereIn('id', EventUserRole::query()
                ->whereHas('role', fn ($q) => $q->where('name', 'owner'))
                ->whereHas('event', fn ($q) => $q->whereBetween('start_date', [now(), now()->addDays(max(1, (int) $upcomingDays))]))
                ->select('user_id')),
            default => $query,
        };
    }

    public function count(string $audience, ?int $upcomingDays = null): int
    {
        return $this->users($audience, $upcomingDays)->count();
    }

    public function includes(User $user, string $audience, ?int $upcomingDays = null): bool
    {
        return $this->users($audience, $upcomingDays)->whereKey($user->id)->exists();
    }
}
