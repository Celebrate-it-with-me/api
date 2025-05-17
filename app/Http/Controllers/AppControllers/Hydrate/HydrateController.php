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
        try {
            return $this->hydrationService->hydrate($user);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
