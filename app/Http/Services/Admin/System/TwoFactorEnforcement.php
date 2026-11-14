<?php

namespace App\Http\Services\Admin\System;

use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Require two-factor for all staff": a switch stored in `system_flags` (key `admin.require_two_factor`), read through a
 * very short cache because the staff middleware asks on every panel request. Off by default. When on, EVERY person with
 * a staff role (superadmin and support) must have two-factor confirmed to use the panel; the environment variable
 * ADMIN_REQUIRE_2FA_SUPERADMIN stays as a separate, additional rule for superadmins (see TwoFactorService::requiredFor),
 * so the panel cannot turn that one off. Emergency exit: `php artisan admin:require-2fa off`.
 */
class TwoFactorEnforcement
{
    public const KEY = 'admin.require_two_factor';

    public const CACHE_KEY = 'admin.require_two_factor.enabled';

    /** The list of people who still have to enrol is capped, the count is not. */
    public const LIST_LIMIT = 50;

    public function enabled(): bool
    {
        // Fail open: if the cache or the database cannot be read, nobody is locked out by the switch.
        try {
            return (bool) Cache::remember(self::CACHE_KEY, 5, fn () => $this->load());
        } catch (\Throwable) {
            return false;
        }
    }

    public function set(bool $enabled, ?User $by): void
    {
        DB::table('system_flags')->upsert([[
            'key' => self::KEY,
            'value' => json_encode(['enabled' => $enabled]),
            'updated_by' => $by?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['key'], ['value', 'updated_by', 'updated_at']);

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{enabled: bool, enrolled: int, total: int, notEnrolled: list<array{id: int, name: string, email: string}>, envForced: bool}
     */
    public function status(): array
    {
        $staff = fn () => User::query()->role(AdminPermissions::STAFF_ROLES);
        $withoutTwoFactor = fn () => $staff()->whereDoesntHave('adminTwoFactor', fn ($q) => $q->whereNotNull('confirmed_at'));

        $total = $staff()->count();
        $notEnrolled = $withoutTwoFactor()->count();

        return [
            'enabled' => $this->enabled(),
            'enrolled' => $total - $notEnrolled,
            'total' => $total,
            'notEnrolled' => $withoutTwoFactor()->orderBy('name')->limit(self::LIST_LIMIT)->get(['users.id', 'users.name', 'users.email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values()->all(),
            'envForced' => (bool) config('admin.require_two_factor_superadmin'),
        ];
    }

    private function load(): bool
    {
        $row = DB::table('system_flags')->where('key', self::KEY)->first();
        $value = $row ? json_decode((string) $row->value, true) : null;

        return is_array($value) && ! empty($value['enabled']);
    }
}
