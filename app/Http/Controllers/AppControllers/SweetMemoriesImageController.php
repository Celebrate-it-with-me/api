<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSweetMemoriesImageRequest;
use App\Http\Resources\AppResources\SweetMemoriesImageResource;
use App\Http\Services\AppServices\SweetMemoriesImageServices;
use App\Models\Events;
use App\Models\SweetMemoriesImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SweetMemoriesImageController extends Controller
{
    private SweetMemoriesImageServices $sweetMemoriesImageServices;

    public function __construct(SweetMemoriesImageServices $sweetMemoriesImageServices)
    {
        $this->sweetMemoriesImageServices = $sweetMemoriesImageServices;
    }

    /**
     * Get all event sweet memories images.
     * @param Events $event
     * @return AnonymousResourceCollection
     */
    public function index(Events $event): AnonymousResourceCollection
    {
        return SweetMemoriesImageResource::collection($event->sweetMemoriesImages);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSweetMemoriesImageRequest $request, Events $event): AnonymousResourceCollection | JsonResponse
    {
        try {
            return SweetMemoriesImageResource::collection($this->sweetMemoriesImageServices->create($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     */
    public function update(StoreSweetMemoriesImageRequest $request, Events $event, SweetMemoriesImage $sweetMemoriesImage): JsonResponse
    {
        try {
            [$result, $message, $status] = $this->sweetMemoriesImageServices->update($request, $sweetMemoriesImage);

            return response()->json(['message' => $message, 'data' => $result], $status);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    public function updateName(Request $request, SweetMemoriesImage $sweetMemoriesImage): JsonResponse
    {
        try {
            [$result, $status] = $this->sweetMemoriesImageServices->updateName($request, $sweetMemoriesImage);

            return response()->json(['data' => $result ], $status);
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
            [$result, $message, $status] = $this->sweetMemoriesImageServices->destroy($event, $sweetMemoriesImage);

            return response()->json(['message' => $message, 'data' => $result], $status);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
