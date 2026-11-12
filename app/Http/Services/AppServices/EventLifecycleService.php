<?php

namespace App\Http\Services\AppServices;

use App\Enums\EventStatus;
use App\Http\Services\Logger\EventActivityLogger;
use App\Models\Events;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes an event's status. Each action states where it may start from;
 * anything else is a 422 with a clear message instead of a silent change.
 */
class EventLifecycleService
{
    /** Make the invitation reachable. Needs the minimum a guest would see: name, date and type. */
    public function publish(Events $event, ?User $actor): Events
    {
        $this->requireStatus($event, [EventStatus::DRAFT], 'Only a draft can be published.');

        $missing = [];
        if (blank($event->event_name)) {
            $missing['eventName'] = 'Add a name before publishing.';
        }
        if (! $event->start_date) {
            $missing['startDate'] = 'Add the date before publishing.';
        }
        if (! $event->event_type_id) {
            $missing['eventType'] = 'Choose the type of event before publishing.';
        }
        if ($missing) {
            throw ValidationException::withMessages($missing);
        }

        // A list over the plan's limit cannot be published (see GuestLimit).
        app(GuestLimit::class)->assertCanPublish($event);

        $event->status = EventStatus::PUBLISHED->value;
        // The first publication is remembered forever: it is what locks the public address.
        $event->published_at ??= now();

        return $this->save($event, $actor, 'event_published');
    }

    /** Take the invitation offline. The address stays locked: the links may already be out. */
    public function unpublish(Events $event, ?User $actor): Events
    {
        $this->requireStatus($event, [EventStatus::PUBLISHED], 'Only a published event can be unpublished.');
        $event->status = EventStatus::DRAFT->value;

        return $this->save($event, $actor, 'event_unpublished');
    }

    /** Put the event away (it also stops serving its invitation). It can be restored. */
    public function archive(Events $event, ?User $actor): Events
    {
        $this->requireStatus($event, [EventStatus::DRAFT, EventStatus::PUBLISHED], 'This event is already archived.');
        $event->status = EventStatus::ARCHIVED->value;

        return $this->save($event, $actor, 'event_archived');
    }

    /** Bring an archived event back as a draft; publishing again is a deliberate step. */
    public function restore(Events $event, ?User $actor): Events
    {
        $this->requireStatus($event, [EventStatus::ARCHIVED], 'Only an archived event can be restored.');
        $event->status = EventStatus::DRAFT->value;

        return $this->save($event, $actor, 'event_restored');
    }

    /** @param list<EventStatus> $allowed */
    private function requireStatus(Events $event, array $allowed, string $message): void
    {
        if (! in_array(EventStatus::tryFrom($event->status), $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function save(Events $event, ?User $actor, string $activity): Events
    {
        $event->save();

        EventActivityLogger::log($event->id, $activity, $actor, $event, [
            'event_name' => $event->event_name,
            'status' => $event->status,
        ]);

        return $event->refresh();
    }
}
