<?php

namespace App\Http\Controllers\AppControllers\EventPermissions;

use App\Http\Controllers\Controller;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventPermissionsController extends Controller
{
    public function __construct() {}

    /**
     * Get the permissions of the logged-in user for a specific event.
     */
    public function index(Request $request, Events $event): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasEventRole($event)) {
            return response()->json([
                'message' => __('errors.permission.event_access'),
                'data' => [],
            ], 403);
        }
        $permissions = $user->getEventPermissions($event);

        return response()->json([
            'message' => 'Permissions retrieved successfully.',
            'data' => [
                'permissions' => $permissions,
                'event' => $event,
            ],
        ]);
    }
}
