<?php

namespace App\Http\Controllers\AppControllers\Hydrate;

use App\Http\Services\AppServices\Hydrate\HydrationService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Throwable;

class HydrateController
{
    protected HydrationService $hydrationService;
    
    public function __construct(HydrationService $hydrationService)
    {
        $this->hydrationService = $hydrationService;
    }
    
    /**
     * Hydrate the user with their events and other related data.
     * @param User $user
     * @return JsonResponse|null
     */
    public function hydrate(User $user): ?JsonResponse
    {
        // Your own data only: this used to answer with any user's events and guests.
        abort_unless(request()->user()?->is($user), 403);

        try {
            $response = $this->hydrationService->hydrate($user);

            // Tells the app it is being used by support (banner). Null for a normal session.
            $session = request()->attributes->get('impersonation_session');
            if ($session && $response instanceof JsonResponse) {
                $response->setData(array_merge((array) $response->getData(true), ['impersonating' => $session->banner()]));
            }

            return $response;
        } catch (Throwable $th) {
            report($th);

            return response()->json(['message' => 'The data could not be loaded.', 'data' => []], 500);
        }
    }
}
