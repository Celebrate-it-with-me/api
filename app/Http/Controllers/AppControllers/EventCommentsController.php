<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreEventCommentRequest;
use App\Http\Requests\app\StoreEventsRequest;
use App\Http\Resources\AppResources\EventCommentResource;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Services\AppServices\EventCommentsServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class EventCommentsController extends Controller
{
    public function __construct(private readonly EventCommentsServices $eventCommentsServices) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Events $event): AnonymousResourceCollection|JsonResponse
    {
        try {
            return EventCommentResource::collection($this->eventCommentsServices->getEventComments($event))
                ->response()->getData(true);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Store user event.
     * @param StoreeventsRequest $request
     * @return JsonResponse|EventResource
     */
    public function store(StoreEventCommentRequest $request, Events $event): JsonResponse|EventCommentResource
    {
        try {
            return EventCommentResource::make($this->eventCommentsServices->createEventComment($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
