<?php

namespace App\Http\Services\Admin\System;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Soft maintenance switch (NOT `artisan down`): a flag in `system_flags`, read through a very short cache so a toggle
 * reaches every worker within seconds. The admin API always stays reachable, and the flag expires by itself after
 * MAX_HOURS so a forgotten switch can never keep the API closed for good. Emergency exit: `php artisan system:maintenance off`.
 */
class MaintenanceMode
{
    public const KEY = 'system.maintenance';

    public const CACHE_KEY = 'system.maintenance.state';

    public const MAX_HOURS = 12;

    public const DEFAULT_MESSAGE = 'Estamos haciendo mejoras y volvemos en unos minutos. Gracias por tu paciencia.';

    /** @return array{active: bool, message: string|null, estimatedEnd: string|null, activatedAt: string|null, expiresAt: string|null, activatedBy: array{id: int, name: string}|null} */
    public function state(): array
    {
        // Fail open: if the cache or the database cannot be read, the API must not close itself.
        try {
            $state = Cache::remember(self::CACHE_KEY, 5, fn () => $this->load());
        } catch (\Throwable) {
            return $this->off();
        }

        if ($state['active'] && $state['expiresAt'] && Carbon::parse($state['expiresAt'])->isPast()) {
            return $this->off();
        }

        return $state;
    }

    public function active(): bool
    {
        return $this->state()['active'];
    }

    public function enable(?string $message, ?Carbon $estimatedEnd, ?User $by): array
    {
        $now = now();
        $value = [
            'message' => $message !== null && trim($message) !== '' ? trim($message) : self::DEFAULT_MESSAGE,
            'estimatedEnd' => $estimatedEnd?->toIso8601String(),
            'activatedAt' => $now->toIso8601String(),
            'expiresAt' => $now->copy()->addHours(self::MAX_HOURS)->toIso8601String(),
        ];
        $this->store(['active' => true] + $value, $by);

        return $this->state();
    }

    public function disable(?User $by): array
    {
        $this->store(['active' => false], $by);

        return $this->state();
    }

    /** Seconds a client should wait before trying again. */
    public function retryAfter(array $state): int
    {
        $end = $state['estimatedEnd'] ? Carbon::parse($state['estimatedEnd']) : null;

        return $end && $end->isFuture() ? max(60, (int) now()->diffInSeconds($end)) : 300;
    }

    private function store(array $value, ?User $by): void
    {
        DB::table('system_flags')->upsert([[
            'key' => self::KEY,
            'value' => json_encode($value),
            'updated_by' => $by?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['key'], ['value', 'updated_by', 'updated_at']);

        Cache::forget(self::CACHE_KEY);
    }

    private function load(): array
    {
        $row = DB::table('system_flags')->where('key', self::KEY)->first();
        $value = $row ? json_decode((string) $row->value, true) : null;
        if (! is_array($value) || empty($value['active'])) {
            return $this->off();
        }

        $by = $row->updated_by ? User::query()->select(['id', 'name'])->find($row->updated_by) : null;

        return [
            'active' => true,
            'message' => $value['message'] ?? self::DEFAULT_MESSAGE,
            'estimatedEnd' => $value['estimatedEnd'] ?? null,
            'activatedAt' => $value['activatedAt'] ?? null,
            'expiresAt' => $value['expiresAt'] ?? null,
            'activatedBy' => $by ? ['id' => $by->id, 'name' => $by->name] : null,
        ];
    }

    private function off(): array
    {
        return ['active' => false, 'message' => null, 'estimatedEnd' => null, 'activatedAt' => null, 'expiresAt' => null, 'activatedBy' => null];
    }
}
