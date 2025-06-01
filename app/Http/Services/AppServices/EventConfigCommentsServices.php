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
     */
    public function createEventConfigComment(Events $event): Model|EventConfigComment
    {
        return EventConfigComment::query()->create([
            'event_id' => $event->id,
            'title' => $this->request->get('title'),
            'sub_title' => $this->request->get('subTitle'),
            'background_color' => $this->request->get('backgroundColor'),
            'comments_title' => $this->request->get('commentsTitle') ?? '',
            'button_color' => $this->request->get('buttonColor'),
            'button_text' => $this->request->get('buttonText'),
            'max_comments' => $this->request->get('maxComments'),
        ]);
    }

    /**
     * Updates the configuration details of the provided EventConfigComment model.
     *
     * @param  EventConfigComment  $eventConfigComment  The EventConfigComment model to update.
     * @return Model|EventConfigComment The updated EventConfigComment model.
     */
    public function updateEventConfigComment(EventConfigComment $eventConfigComment): Model|EventConfigComment
    {
        $eventConfigComment->title = $this->request->get('title');
        $eventConfigComment->sub_title = $this->request->get('subTitle');
        $eventConfigComment->background_color = $this->request->get('backgroundColor');
        $eventConfigComment->comments_title = $this->request->get('commentsTitle');
        $eventConfigComment->button_color = $this->request->get('buttonColor');
        $eventConfigComment->button_text = $this->request->get('buttonText');
        $eventConfigComment->max_comments = $this->request->get('maxComments');

        $eventConfigComment->save();

        return $eventConfigComment;
    }
}
