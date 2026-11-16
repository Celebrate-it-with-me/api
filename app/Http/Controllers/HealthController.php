<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * `GET /up`: liveness/readiness for the PaaS router or a load balancer. Light on purpose (a `select 1` and a Redis
 * PING, no queries on tables) and outside every group: no throttle, session, cookies or maintenance switch, so a
 * balancer can poll it every few seconds. Redis is only checked when cache, session or queue actually use it.
 * It never says why a check failed (that goes to the log), so it is safe to leave public. The older
 * `public/system-status` is the SPA's maintenance notice and is a different thing.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['app' => 'ok', 'database' => $this->check(fn () => DB::select('select 1')), 'redis' => $this->usesRedis() ? $this->check(fn () => Redis::connection()->ping()) : 'skipped'];

        $healthy = ! in_array('fail', $checks, true);

        return response()->json(['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks], $healthy ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    private function usesRedis(): bool
    {
        return config('cache.stores.' . config('cache.default') . '.driver') === 'redis'
            || config('session.driver') === 'redis'
            || config('queue.connections.' . config('queue.default') . '.driver') === 'redis';
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            Log::warning('Health check failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return 'fail';
        }
    }
}
