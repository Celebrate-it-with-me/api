<?php

namespace App\Http\Controllers\AppControllers\EventPermissions;

use App\Http\Controllers\Controller;
use App\Http\Services\AppServices\EventsServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventPermissionsController extends Controller
{
    public function __construct(private readonly EventsServices $eventsServices) {}

    /**
     * Get the permissions of the logged-in user for a specific event.
     */
    public function index(Request $request, Events $event): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasEventRole($event)) {
            return response()->json([
                'message' => 'You do not have permission to access this event.',
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
