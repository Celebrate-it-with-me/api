<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Services\AppServices\CompanionServices;
use App\Models\GuestCompanion;
use Illuminate\Http\JsonResponse;
use Throwable;

class CompanionController extends Controller
{
    public function __construct(private readonly CompanionServices $companionServices) {}
    
    /**
     * Deletes the specified guest companion.
     *
     * @param GuestCompanion $guestCompanion The guest companion to be deleted.
     * @return JsonResponse|EventResource Returns a JSON response with a success message and associated data if successful, or with an error message in case of an exception.
     */
    public function destroy(GuestCompanion $guestCompanion): JsonResponse|EventResource
    {
        try {
            return response()->json([
                'message' => 'Companion deleted successfully.',
                'data' => $this->companionServices->removeCompanion($guestCompanion)
            ]);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
