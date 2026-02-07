<?php

namespace App\Http\Services\AppServices\Hydrate;

use App\Http\Resources\AppResources\EventPlansResource;
use App\Http\Resources\AppResources\EventTypesResource;
use App\Models\EventPlan;
use App\Models\EventType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EventCacheService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const EVENT_TYPES_KEY = 'hydration.event_types';
    private const EVENT_PLANS_KEY = 'hydration.event_plans';

    /**
     * Get all event types with caching.
     *
     * Performance optimization: Event types rarely change, so we cache them
     * to avoid repeated database queries.
     *
     * @return Collection
     */
    public function getEventTypes(): Collection
    {
        return Cache::remember(
            self::EVENT_TYPES_KEY,
            self::CACHE_TTL,
            function () {
                return EventType::query()
                    ->select('id', 'name', 'slug', 'icon')
                    ->get();
            }
        );
    }

    /**
     * Get all event plans with caching.
     *
     * Performance optimization: Event plans are static data, perfect for caching.
     *
     * @return Collection
     */
    public function getEventPlans(): Collection
    {
        return Cache::remember(
            self::EVENT_PLANS_KEY,
            self::CACHE_TTL,
            function () {
                return EventPlan::query()->get();
            }
        );
    }

    /**
     * Clear cached event types and plans.
     *
     * Use this when event types or plans are modified.
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::forget(self::EVENT_TYPES_KEY);
        Cache::forget(self::EVENT_PLANS_KEY);
    }

    /**
     * Warm up the cache by preloading data.
     *
     * Useful for deployment or scheduled tasks.
     *
     * @return void
     */
    public function warmUp(): void
    {
        $this->getEventTypes();
        $this->getEventPlans();
    }
}
