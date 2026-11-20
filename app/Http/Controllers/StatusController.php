<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class StatusController extends Controller
{
    /** Seconds the answer is reused, so polling `/status` cannot open a database connection per request (R1-I-3). */
    public const CACHE_SECONDS = 5;

    /**
     * Legacy probe: true when the database answers. The load balancer should poll `/up` instead; this endpoint is
     * throttled (`status` limiter) and its answer is cached for a few seconds.
     */
    public function __invoke(): JsonResponse
    {
        try {
            $ok = (bool) Cache::remember('status.database', self::CACHE_SECONDS, fn () => self::probe());
        } catch (Throwable) {
            // The cache store itself is down: probe directly rather than failing the check for the wrong reason.
            $ok = self::probe();
        }

        return response()->json($ok, 200, ['Cache-Control' => 'no-store']);
    }

    private static function probe(): bool
    {
        try {
            DB::connection()->getPdo();

            return DB::connection()->getDatabaseName() !== 'none';
        } catch (Throwable) {
            return false;
        }
    }
}
