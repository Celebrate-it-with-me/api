<?php

namespace App\Http\Controllers\AppControllers\SweetMemory;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSweetMemoriesImageRequest;
use App\Http\Requests\app\StoreSweetMemoryRequest;
use App\Http\Resources\AppResources\SweetMemoriesImageResource;
use App\Http\Resources\AppResources\SweetMemoryResource;
use App\Http\Services\AppServices\SweetMemory\SweetMemoryServices;
use App\Models\Events;
use App\Models\SweetMemoriesImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class SweetMemoryController extends Controller
{
    private SweetMemoryServices $sweetMemoryServices;

    public function __construct(SweetMemoryServices $sweetMemoryServices)
    {
        $this->sweetMemoryServices = $sweetMemoryServices;
    }

    /**
     * Get all event sweet memories images.
     */
    public function index(Events $event): AnonymousResourceCollection
    {
        return SweetMemoryResource::collection($event->sweetMemories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSweetMemoryRequest $request, Events $event): SweetMemoryResource|JsonResponse
    {
        try {
            return SweetMemoryResource::make($this->sweetMemoryServices->create($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreSweetMemoriesImageRequest $request, Events $event): JsonResponse|AnonymousResourceCollection
    {
        try {
            return SweetMemoriesImageResource::collection($this->sweetMemoryServices->create($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    public function updateName(Request $request, SweetMemoriesImage $sweetMemoriesImage): JsonResponse
    {
        try {
            [$result, $status] = $this->sweetMemoryServices->updateName($request, $sweetMemoriesImage);

            return response()->json(['data' => $result], $status);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Events $event, SweetMemoriesImage $sweetMemoriesImage): JsonResponse
    {
        try {
            [$result, $message, $status] = $this->sweetMemoryServices->destroy($event, $sweetMemoriesImage);

            return response()->json(['message' => $message, 'data' => $result], $status);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
