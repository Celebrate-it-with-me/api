<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreEventCommentRequest;
use App\Http\Resources\AppResources\EventCommentResource;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Services\AppServices\EventCommentsServices;
use App\Models\EventComment;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventCommentsController extends Controller
{
    public function __construct(private readonly EventCommentsServices $eventCommentsServices) {}

    /**
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
     *
     * @return JsonResponse|EventResource
     */
    public function store(StoreEventCommentRequest $request, Events $event): JsonResponse|EventCommentResource
    {
        try {
            return EventCommentResource::make($this->eventCommentsServices->createEventComment($event));
        } catch (Throwable $th) {
            Log::error('Comment Error info', [
                'message' => $th->getMessage(),
                'request' => $request->all(),
                'event_id' => $event->id,
            ]);

            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    public function adminIndex(Request $request, Events $event): mixed
    {
        try {
            return EventCommentResource::collection($this->eventCommentsServices->getAdminEventComments($event))
                ->response()->getData(true);
        } catch (Throwable $th) {
            Log::error('Admin Comment Index Error', [
                'message' => $th->getMessage(),
                'request' => $request->all(),
                'event_id' => $event->id,
            ]);

            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    
    /**
     * Store a new comment for the event.
     * This method handles comment creation from admin
     * @param Request $request
     * @param Events $event
     * @return EventCommentResource|JsonResponse
     */
    public function storeComment(Request $request, Events $event): EventCommentResource | JsonResponse
    {
        try {
            return EventCommentResource::make($this->eventCommentsServices->createAdminComment($event));
        } catch (Throwable $th) {
            Log::error('Comment Error info', [
                'message' => $th->getMessage(),
                'request' => $request->all(),
                'event_id' => $event->id,
            ]);

            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Toggle the visibility status of a specified event comment.
     *
     * @param Request $request
     * @param Events $event
     * @param EventComment $comment
     * @return JsonResponse
     */
    public function toggleVisibility(Request $request, Events $event, EventComment $comment): JsonResponse
    {
        try {
            $comment->is_approved = !$comment->is_approved;
            $comment->save();

            return response()->json(['message' => 'Comment visibility toggled successfully.', 'data' => EventCommentResource::make($comment)]);
        } catch (Throwable $th) {
            Log::error('Toggle Comment Visibility Error', [
                'message' => $th->getMessage(),
                'request' => $request->all(),
                'event_id' => $event->id,
                'comment_id' => $comment->id,
            ]);

            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Delete a comment from the event.
     *
     * @param Request $request
     * @param Events $event
     * @param EventComment $comment
     * @return JsonResponse
     */
    public function destroy(Request $request, Events $event, EventComment $comment): JsonResponse
    {
        try {
            $comment->delete();

            return response()->json(['message' => 'Comment deleted successfully.', 'data' => []]);
        } catch (Throwable $th) {
            Log::error('Delete Comment Error', [
                'message' => $th->getMessage(),
                'request' => $request->all(),
                'event_id' => $event->id,
                'comment_id' => $comment->id,
            ]);

            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
