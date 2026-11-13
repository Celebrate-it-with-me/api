<?php

namespace App\Http\Middleware;

use App\Http\Services\Admin\System\MaintenanceMode;
use App\Support\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global. While the soft maintenance flag is on, the public API answers 503 `{code: "maintenance"}`.
 * Always reachable: the admin API (so staff can switch it off), the public status endpoint, the payments webhook
 * (the provider must not lose events), and anything that is not under /api. Staff with a valid token pass everywhere.
 */
class SystemMaintenance
{
    private const ALLOWED = ['api/v1/admin', 'api/v1/admin/*', 'api/v1/public/system-status', 'api/v1/app/payments/webhook'];

    public function __construct(private readonly MaintenanceMode $maintenance) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*') || $request->is(self::ALLOWED) || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $state = $this->maintenance->state();
        if (! $state['active'] || $this->isStaff($request)) {
            return $next($request);
        }

        $retryAfter = $this->maintenance->retryAfter($state);

        return response()->json([
            'code' => 'maintenance',
            'message' => $state['message'],
            'retryAfter' => $retryAfter,
        ], 503, ['Retry-After' => (string) $retryAfter]);
    }

    private function isStaff(Request $request): bool
    {
        try {
            return (bool) $request->user('sanctum')?->hasAnyRole(AdminPermissions::STAFF_ROLES);
        } catch (\Throwable) {
            return false;
        }
    }
}
