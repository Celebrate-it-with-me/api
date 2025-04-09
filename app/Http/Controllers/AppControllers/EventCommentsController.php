<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreEventCommentRequest;
use App\Http\Resources\AppResources\EventCommentResource;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Services\AppServices\EventCommentsServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Throwable;

class EventCommentsController extends Controller
{
    public function __construct(private readonly EventCommentsServices $eventCommentsServices) {}
    
    /**
     * @param Events $event
     * @return JsonResponse|mixed
     */
    public function index(Events $event): mixed
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
     * @param StoreEventCommentRequest $request
     * @param Events $event
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
