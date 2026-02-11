<?php

namespace App\Http\Controllers\AppControllers\EventComment;

use App\Enums\EventCommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\app\EventComment\OrganizerCreateEventCommentRequest;
use App\Http\Requests\app\EventComment\OrganizerListEventCommentsRequest;
use App\Http\Requests\app\EventComment\OrganizerUpdateCommentStatusRequest;
use App\Http\Requests\app\EventComment\PublicCreateEventCommentRequest;
use App\Http\Resources\AppResources\EventComment\EventCommentResource;
use App\Http\Services\EventComment\EventCommentService;
use App\Models\EventComment;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicEventCommentController extends Controller
{
    public function __construct(
        private readonly EventCommentService $eventCommentService
    ) {}

    /**
     * Handles the retrieval of public event comments for a specified event.
     *
     * @param Events $event The event for which comments are to be retrieved.
     * @return JsonResponse A JSON response containing the list of comments and associated metadata.
     */
    public function index(Events $event): JsonResponse
    {
        $comments = $this->eventCommentService->listPublicForEvent($event);

        return response()->json([
            'data' => EventCommentResource::collection($comments),
        ]);
    }

    /**
     * Stores a new comment for the specified event as a guest.
     *
     * @param PublicCreateEventCommentRequest $request The incoming request containing the comment data.
     * @param Events $event The event to which the comment is associated.
     * @return JsonResponse The response containing the created comment resource.
     */
    public function store(PublicCreateEventCommentRequest $request, Events $event): JsonResponse
    {
        $payload = $request->validated();

        $guest = Guest::query()
            ->where('event_id', $event->id)
            ->where('code', $payload['accessCode'])
            ->firstOrFail();

        $requiresApproval = false;

        $comment = $this->eventCommentService->createAsGuest(
            $event,
            $guest,
            $payload['comment'],
            $requiresApproval
        );

        $comment->load('authorable');

        return response()->json([
            'data' => new EventCommentResource($comment),
        ], 201);
    }
}
