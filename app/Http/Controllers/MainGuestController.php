<?php

namespace App\Http\Controllers;

use App\Http\Requests\MainGuest\CreateMainGuestRequest;
use App\Http\Resources\MainGuestResource;
use App\Http\Resources\UserResource;
use App\Http\Services\MainGuestServices;
use App\Models\MainGuest;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MainGuestController extends Controller
{
    public function __construct(private MainGuestServices $mgService){}

    /**
     * Main Guest index.
     * @param Request $request
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        try {
            return $this->mgService->getMainGuestWithPagination();
        } catch(Exception $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Create a new main guest.
     * @param CreateMainGuestRequest $request
     * @return MainGuestResource | JsonResponse
     */
    public function store(CreateMainGuestRequest $request): MainGuestResource | JsonResponse
    {
        try {
            return MainGuestResource::make($this->mgService->create());
        } catch(Exception $e) {
            return response()->json(['message' => 'Ops something fail '.$e->getMessage()],409);
        }
    }

    /**
     * Get Main guest.
     * @param MainGuest $mainGuest
     * @return MainGuestResource|JsonResponse
     */
    public function show(MainGuest $mainGuest): MainGuestResource|JsonResponse
    {
        try {
            return MainGuestResource::make($mainGuest);
        } catch(Exception $e) {
            return response()->json(['message' => 'Ops something fail '.$e->getMessage()],409);
        }
    }

    /**
     * Update Main Guest info.
     * @param CreateMainGuestRequest $request
     * @param User $user
     * @return UserResource|JsonResponse
     */
    public function update(CreateMainGuestRequest $request, MainGuest $mainGuest): MainGuestResource|JsonResponse
    {
        try {
            return MainGuestResource::make($this->mgService->update($mainGuest));
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail '.$e->getMessage()],409);
        }
    }

    /**
     * Remove Main Guest from database.
     * @param MainGuest $mainGuest
     * @return MainGuestResource|JsonResponse
     */
    public function destroy(MainGuest $mainGuest): MainGuestResource|JsonResponse
    {
        try {
            return MainGuestResource::make($this->mgService->destroy($mainGuest));
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail '.$e->getMessage()],409);
        }
    }
}
