<?php

namespace App\Http\Services\AppServices;

use App\Models\EventComment;
use App\Models\Events;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class EventCommentsServices
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    /**
     * Retrieves paginated comments for a given event, specifically for admin view.
     *
     * @param Events $event
     * @return LengthAwarePaginator
     */
    public function getAdminEventComments(Events $event): LengthAwarePaginator
    {
        $search = $this->request->query('search', '');
        $page = $this->request->query('page', 1);
        $perPage = $this->request->query('perPage', 5);
        
        
        return $event->comments()
            ->where('comment', 'like', '%' . $search . '%')
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }
    
    
    /**
     * Retrieves paginated comments for a given event.
     *
     * @param  Events  $event  The event instance for which comments are being retrieved.
     * @return Paginator The paginated list of comments.
     */
    public function getEventComments(Events $event): Paginator
    {
        $commentsQuery = $event->comments()->latest();
        $perPage = $event->eventConfigComment->max_comments ?? 5;
        $page = $this->request->query('page', 1);

        return $commentsQuery->paginate($perPage, ['*'], 'page', $page);
    }

    public function createEventComment(Events $event): EventComment
    {
        $createdByClass = Guest::class;
        if ($this->request->input('origin') === 'admin') {
            $createdByClass = User::class;
        }

        return EventComment::query()->create([
            'event_id' => $event->id,
            'created_by_class' => $createdByClass,
            'created_by_id' => $this->request->input('userId'),
            'comment' => $this->request->input('comment'),
            'is_approved' => 1,
        ]);
    }
    
    /**
     * Store a new comment for the event.
     * This method handles comment creation from admin.
     *
     * @param  Events  $event
     * @return EventComment
     */
    public function createAdminComment(Events $event): EventComment
    {
        return EventComment::query()->create([
            'event_id' => $event->id,
            'created_by_class' => User::class,
            'created_by_id' => $this->request->user()->id,
            'comment' => $this->request->input('comment'),
            'is_approved' => 1,
        ]);
    }
}
