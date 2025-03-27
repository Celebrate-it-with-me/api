<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreEventConfigCommentRequest;
use App\Http\Resources\AppResources\EventConfigCommentResource;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Services\AppServices\EventConfigCommentsServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Throwable;

class EventConfigCommentsController extends Controller
{
    public function __construct(private readonly EventConfigCommentsServices $eventConfigCommentsServices) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Events $event): EventConfigCommentResource|JsonResponse
    {
        try {
            return EventConfigCommentResource::make($event->eventConfigComment);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Store event comments configuration.
     * @param StoreEventConfigCommentRequest $request
     * @param Events $event
     * @return JsonResponse|EventResource
     */
    public function store(StoreEventConfigCommentRequest $request, Events $event): JsonResponse|EventConfigCommentResource
    {
        try {
            return EventConfigCommentResource::make($this->eventConfigCommentsServices->createEventConfigComment($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
