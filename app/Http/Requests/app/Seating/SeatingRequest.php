<?php

namespace App\Http\Requests\app\Seating;

use App\Models\Events;
use Illuminate\Foundation\Http\FormRequest;

/** Base of the seating write requests: whoever changes seating needs the event's `edit_event` permission (owner or editor, never a viewer). */
abstract class SeatingRequest extends FormRequest
{
    protected function event(): Events
    {
        $event = $this->route('event');

        return $event instanceof Events ? $event : Events::query()->findOrFail($event);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array('edit_event', $user->getEventPermissions($this->event()), true);
    }
}
