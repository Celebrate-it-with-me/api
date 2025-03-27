<?php

namespace App\Http\Services\AppServices;

use App\Models\EventComment;
use App\Models\Events;
use App\Models\MainGuest;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;

class EventCommentsServices
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    /**
     * Retrieves paginated comments for a given event.
     *
     * @param Events $event The event instance for which comments are being retrieved.
     * @return Paginator The paginated list of comments.
     */
    public function getEventComments(Events $event): Paginator
    {
        $commentsQuery = $event->comments()->latest();
        $perPage = 5;
        $page = $this->request->query('page', 1);
        
        return $commentsQuery->paginate($perPage, ['*'], 'page', $page);
    }
    
    public function createEventComment(Events $event): EventComment
    {
        $createdByClass = MainGuest::class;
        if ($this->request->input('mode') === 'creator') {
            $createdByClass = User::class;
        }
        
        return EventComment::query()->create([
            'event_id' => $event->id,
            'created_by_class' => $createdByClass,
            'created_by_id' => $this->request->input('created_by_id'),
            'comment' => $this->request->input('comment'),
            'is_approved' => 1,
        ]);
    }
}
