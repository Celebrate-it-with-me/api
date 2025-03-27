<?php

namespace App\Http\Services\AppServices;

use App\Models\EventConfigComment;
use App\Models\Events;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class EventConfigCommentsServices
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    /**
     * Create comments configurations
     * @param Events $event
     * @return Model|EventConfigComment
     */
    public function createEventConfigComment(Events $event): Model|EventConfigComment
    {
        return EventConfigComment::query()->create([
            'event_id' => $event->id,
            'title' => $this->request->get('title'),
            'sub_title' => $this->request->get('subTitle'),
            'background_color' => $this->request->get('backgroundColor'),
            'comments_title' => $this->request->get('commentsTitle'),
            'max_comments' => $this->request->get('maxComments'),
        ]);
    }
}
