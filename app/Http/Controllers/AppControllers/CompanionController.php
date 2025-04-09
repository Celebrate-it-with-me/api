<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreGuestCompanionRequest;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Services\AppServices\CompanionServices;
use App\Models\GuestCompanion;
use App\Models\MainGuest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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
    
    /**
     * Handles the storage of a new companion for the given main guest.
     *
     * @param StoreGuestCompanionRequest $request The request instance containing validation logic and input data for creating a companion.
     * @param MainGuest $guest The main guest instance associated with the companion to be created.
     *
     * @return JsonResponse A JSON response indicating whether the companion creation was successful or if an error occurred.
     */
    public function store(StoreGuestCompanionRequest $request, MainGuest $guest): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Companion created successfully.',
                'data' => $this->companionServices->createCompanion($guest)
            ]);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    
    /**
     * Updates an existing companion for the given guest companion.
     *
     * @param StoreGuestCompanionRequest $request The request instance containing validation logic and input data for updating the companion.
     * @param GuestCompanion $companion The guest companion to be updated.
     *
     * @return JsonResponse A JSON response indicating whether the companion update was successful or if an error occurred.
     */
    public function update(StoreGuestCompanionRequest $request, GuestCompanion $companion): JsonResponse
    {
        try {
            Log::info('debuging 1');
            return response()->json([
                'message' => 'Companion updated successfully.',
                'data' => $this->companionServices->updateCompanion($companion)
            ]);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(). ' '.$th->getFile() . ' ' .$th->getLine() , 'data' => []], 500);
        }
    }
}
