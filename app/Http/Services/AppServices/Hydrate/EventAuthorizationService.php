<?php

namespace App\Http\Services\AppServices\Hydrate;

use App\Models\Events;
use App\Models\User;

class EventAuthorizationService
{
    /**
     * Determines if the given user has permission to view the specified event.
     *
     * @param User $user The user whose permissions are being checked.
     * @param Events $event The event for which the view permission is being verified.
     * @return bool True if the user has permission to view the event, false otherwise.
     */
    public function canViewEvent(User $user, Events $event): bool
    {
        return $user->hasEventPermission($event, 'view_event');
    }

    /**
     * Retrieves the permissions of a user for a specific event.
     *
     * @param User $user The user whose permissions are to be retrieved.
     * @param Events $event The event for which permissions are being checked.
     * @return array|null An array of permissions if available, or null if no permissions exist.
     */
    public function getUserPermissions(User $user, Events $event): ?array
    {
        return $user->getEventPermissions($event);
    }

    /**
     * Validates whether the user can view the specified event and retrieves the associated permissions.
     *
     * @param User $user The user whose permissions are being checked.
     * @param Events $event The event for which access and permissions are being validated.
     * @return array|null An array of permissions if the user has access, or null if access is denied.
     */
    public function validateAndGetPermissions(User $user, Events $event): ?array
    {
        if (!$this->canViewEvent($user, $event)) {
            return null;
        }

        return $this->getUserPermissions($user, $event);
    }
}
