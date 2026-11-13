<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSweetMemoriesImageRequest;
use App\Http\Requests\app\UpdateSweetMemoriesImageRequest;
use App\Http\Resources\AppResources\SweetMemoriesImageResource;
use App\Http\Services\AppServices\SweetMemoriesImageServices;
use App\Models\Events;
use App\Models\SweetMemoriesImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
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
            report($th);

            return response()->json(['message' => 'The images could not be saved.', 'data' => []], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     */
    public function update(UpdateSweetMemoriesImageRequest $request, Events $event, SweetMemoriesImage $sweetMemoriesImage): JsonResponse
    {
        abort_unless($sweetMemoriesImage->event_id === $event->id, 404);

        try {
            [$result, $message, $status] = $this->sweetMemoriesImageServices->update($request, $sweetMemoriesImage);

            return response()->json(['message' => $message, 'data' => $result], $status);
        } catch (Throwable $th) {
            report($th);

            return response()->json(['message' => 'The image could not be updated.', 'data' => []], 500);
        }
    }

    public function updateName(Request $request, SweetMemoriesImage $sweetMemoriesImage): JsonResponse
    {
        // This route has no event in its address, so the event comes from the image: only someone who can
        // edit that event may rename it. Anyone else gets the same 404 as for an image that does not exist.
        $event = $sweetMemoriesImage->event;
        abort_unless($event && in_array('edit_event', $request->user()->getEventPermissions($event), true), 404);

        try {
            $request->validate(['name' => ['required', 'string', 'max:160']]);
            [$result, $status] = $this->sweetMemoriesImageServices->updateName($request, $sweetMemoriesImage);

            return response()->json(['data' => $result], $status);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            report($th);

            return response()->json(['message' => 'The name could not be updated.', 'data' => []], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Events $event, SweetMemoriesImage $sweetMemoriesImage): JsonResponse
    {
        abort_unless($sweetMemoriesImage->event_id === $event->id, 404);

        try {
            [$result, $message, $status] = $this->sweetMemoriesImageServices->destroy($event, $sweetMemoriesImage);

            return response()->json(['message' => $message, 'data' => $result], $status);
        } catch (Throwable $th) {
            report($th);

            return response()->json(['message' => 'The image could not be deleted.', 'data' => []], 500);
        }
    }
}
