<?php

namespace App\Http\Controllers\AppControllers\EventComment;

use App\Enums\EventCommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\app\EventComment\OrganizerCreateEventCommentRequest;
use App\Http\Requests\app\EventComment\OrganizerListEventCommentsRequest;
use App\Http\Requests\app\EventComment\OrganizerUpdateCommentStatusRequest;
use App\Http\Resources\AppResources\EventComment\EventCommentResource;
use App\Http\Services\EventComment\EventCommentService;
use App\Models\EventComment;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrganizerEventCommentController extends Controller
{
    public function __construct(
        private readonly EventCommentService $eventCommentService
    ) {}

    /**
     * Handles the retrieval of event comments for a specified event, applying filters if provided.
     *
     * @param OrganizerListEventCommentsRequest $request The incoming request containing validation rules and filters.
     * @param Events $event The event for which comments are to be retrieved.
     * @return JsonResponse A JSON response containing the list of comments and associated metadata.
     */
    public function index(OrganizerListEventCommentsRequest $request, Events $event): JsonResponse
    {
        $filters = $request->validated();

        $comments = $this->eventCommentService->listForEvent($event, $filters);

        return response()->json([
            'data' => EventCommentResource::collection($comments),
            'meta' => [
                'counts' => [
                    'total' => $comments->count(),
                    'visible' => $comments->where('status', EventCommentStatus::VISIBLE)->count(),
                    'hidden' => $comments->where('status', EventCommentStatus::HIDDEN)->count(),
                    'pendingReview' => $comments->where('status', EventCommentStatus::PENDING_REVIEW)->count(),
                    'pinned' => $comments->where('is_pinned', true)->count(),
                    'favorites' => $comments->where('is_favorite', true)->count(),
                ],
            ],
        ]);
    }

    /**
     * Handles the paginated retrieval of event comments for a specified event.
     *
     * @param OrganizerListEventCommentsRequest $request
     * @param Events $event
     * @return JsonResponse
     */
    public function indexPaginated(OrganizerListEventCommentsRequest $request, Events $event): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $request->input('perPage', 10);

        $comments = $this->eventCommentService->listForEventPaginated($event, $filters, $perPage);

        return EventCommentResource::collection($comments)->toResponse($request);
    }

    /**
     * Handles the creation and storage of a new event comment as an organizer.
     *
     * @param OrganizerCreateEventCommentRequest $request The request containing the data to create the comment.
     * @param Events $event The event to which the comment is associated.
     *
     * @return JsonResponse A JSON response containing the newly created comment data.
     */
    public function store(OrganizerCreateEventCommentRequest $request, Events $event)
    {
        $user = $request->user();

        $comment = $this->eventCommentService->createAsOrganizer(
            $event,
            $user,
            $request->validated()['comment']
        );

        $comment->load('authorable');

        return response()->json([
           'data' => new EventCommentResource($comment)
        ], 201);
    }

    /**
     * Updates the status of a specific comment associated with an event.
     *
     * @param OrganizerUpdateCommentStatusRequest $request
     * @param Events $event
     * @param EventComment $comment
     * @return JsonResponse
     */
    public function updateStatus(OrganizerUpdateCommentStatusRequest $request, Events $event, EventComment $comment): JsonResponse
    {
        $status = EventCommentStatus::from($request->validated()['status']);

        $updated = $this->eventCommentService->updateStatus($comment, $status);
        $updated->load('authorable');

        return response()->json([
           'data' => new EventCommentResource($updated)
        ]);
    }

    /**
     * Toggles the pinned status of a specific comment associated with an event.
     *
     * @param Request $request The incoming HTTP request instance.
     * @param Events $event The event associated with the comment.
     * @param EventComment $comment The comment whose pinned status is to be toggled.
     *
     * @return JsonResponse A JSON response containing the updated comment data with its new pinned status.
     */
    public function togglePinned(Request $request, Events $event, EventComment $comment): JsonResponse
    {
        $updated = $this->eventCommentService->togglePinned($comment);
        $updated->load('authorable');

        return response()->json([
            'data' => new EventCommentResource($updated),
        ]);
    }

    /**
     * Toggles the favorite status of a given comment on an event.
     *
     * @param Request $request The incoming HTTP request.
     * @param Events $event The event associated with the comment.
     * @param EventComment $comment The comment whose favorite status is to be toggled.
     *
     * @return JsonResponse A JSON response containing the updated comment data.
     */
    public function toggleFavorite(Request $request, Events $event, EventComment $comment): JsonResponse
    {
        $updated = $this->eventCommentService->toggleFavorite($comment);
        $updated->load('authorable');

        return response()->json([
            'data' => new EventCommentResource($updated),
        ]);
    }

    /**
     * Deletes an existing event comment associated with a specified event.
     *
     * @param Request $request The request object containing user context and input data.
     * @param Events $event The event to which the comment belongs.
     * @param EventComment $comment The comment to be deleted.
     *
     * @return JsonResponse A JSON response indicating successful deletion with a 204 status code.
     */
    public function destroy(Request $request, Events $event, EventComment $comment): JsonResponse
    {
        $this->eventCommentService->delete($comment);

        return response()->json(status: 204);
    }
}
