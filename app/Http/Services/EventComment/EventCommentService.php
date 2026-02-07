<?php

namespace App\Http\Services\EventComment;

use App\Enums\EventCommentStatus;
use App\Models\EventComment;
use App\Models\Events;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Support\Collection;

class EventCommentService
{
    /**
     * Retrieves a collection of comments associated with a specific event, optionally filtered based on the provided criteria.
     *
     * @param Events $event The event for which comments should be retrieved.
     * @param array $filters Optional filters to refine the retrieved comments. Supported keys:
     * - `status`: The status of the comments to filter by.
     * - `pinned`: Whether to include only pinned comments.
     * - `favorite`:*/
    public function listForEvent(Events $event, array $filters = []): Collection
    {
        return EventComment::query()
            ->forEvent($event)
            ->with(['authorable'])
            ->withStatus(data_get($filters, 'status'))
            ->pinned(data_get($filters, 'pinned'))
            ->favorite(data_get($filters, 'favorite'))
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Retrieves a paginated collection of comments associated with a specific event, optionally filtered based on the provided criteria.
     *
     * @param Events $event The event for which comments should be retrieved.
     * @param array $filters Optional filters to refine the retrieved comments.
     * @param int $perPage The number of items per page.
     */
    public function listForEventPaginated(Events $event, array $filters = [],int $perPage = 10)
    {
        return EventComment::query()
            ->forEvent($event)
            ->when(isset($filters['status']), function ($query) use ($filters) {
                $query->withStatus(data_get($filters, 'status'));
            })
            ->when(isset($filters['pinned']), function ($query) use ($filters) {
                $query->pinned(data_get($filters, 'pinned'));
            })
            ->when(isset($filters['favorite']), function ($query) use ($filters) {
                $query->favorite(data_get($filters, 'favorite'));
            })
            ->with(['authorable'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Retrieves a collection of publicly visible comments associated with a specific event, with pagination.
     *
     * @param Events $event The event for which publicly visible comments should be retrieved.
     * @param int $perPage The number of items per page.
     */
    public function listPublicForEvent(Events $event, int $perPage = 10)
    {
        return EventComment::query()
            ->forEvent($event)
            ->where('status', EventCommentStatus::VISIBLE->value)
            ->with(['authorable'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Creates a new comment for a given event as a guest user.
     *
     * @param Events $event The event to which the comment should be associated.
     * @param Guest $guest The guest user creating the comment.
     * @param string $commentText The text content of the comment.
     * @param bool $requiresApproval Specifies whether the comment requires approval before it becomes visible. Defaults to false.
     * @return EventComment The newly created comment instance associated with the event and guest user.
     */
    public function createAsGuest(Events $event, Guest $guest, string $commentText, bool $requiresApproval = false): EventComment
    {
        $status = $requiresApproval
            ? EventCommentStatus::PENDING_REVIEW
            : EventCommentStatus::VISIBLE;

        return EventComment::create([
            'event_id' => $event->id,
            'authorable_type' => $guest::class,
            'authorable_id' => $guest->id,
            'comment' => $commentText,
            'status' => $status->value,
            'is_pinned' => false,
            'is_favorite' => false,
        ]);
    }

    /**
     * Creates a new comment for a specified event, authored by the given user, and marked as visible.
     *
     * @param Events $event The event to which the comment will be associated.
     * @param User $user The user who is the author of the comment.
     * @param string $commentText The content of the comment to be created.
     * @return EventComment The newly created comment instance.
     */
    public function createAsOrganizer(Events $event, User $user, string $commentText): EventComment
    {
        return EventComment::create([
            'event_id' => $event->id,
            'authorable_type' => $user::class,
            'authorable_id' => $user->id,
            'comment' => $commentText,
            'status' => EventCommentStatus::VISIBLE->value,
            'is_pinned' => false,
            'is_favorite' => false,
        ]);
    }

    /**
     * Update comment moderation status.
     */
    public function updateStatus(EventComment $comment, EventCommentStatus $status): EventComment
    {
        $comment->status = $status->value;
        $comment->save();

        return $comment;
    }

    /**
     * Toggle pinned flag.
     */
    public function togglePinned(EventComment $comment): EventComment
    {
        $comment->is_pinned = !$comment->is_pinned;
        $comment->save();

        return $comment;
    }

    /**
     * Toggle favorite flag.
     */
    public function toggleFavorite(EventComment $comment): EventComment
    {
        $comment->is_favorite = !$comment->is_favorite;
        $comment->save();

        return $comment;
    }

    /**
     * Soft delete a comment.
     */
    public function delete(EventComment $comment): void
    {
        $comment->delete();
    }
}
