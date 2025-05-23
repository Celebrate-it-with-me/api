<?php

namespace App\Http\Services\Permissions;

use App\Models\Events;
use Illuminate\Http\Request;

class EventPermissionService
{
    private Request $request;
    
    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    
    /**
     * Get the permissions of the logged-in user for a specific event.
     *
     * @param Events $event
     * @return array
     */
    public function getForLoggedUser(Events $event): array
    {
        $user = $this->request->user();
        
        if (!$user->hasEventRole($event)) {
            return [
                'message' => 'You do not have permission to access this event.',
                'data' => [],
                'status' => 403,
            ];
        }
        
        $permissions = $user->getEventPermissions($event);
        
        return [
            'message' => 'Permissions retrieved successfully.',
            'status' => 200,
            'data' => [
                'permissions' => $permissions,
                'event' => $event,
            ]
        ];
    }
}
