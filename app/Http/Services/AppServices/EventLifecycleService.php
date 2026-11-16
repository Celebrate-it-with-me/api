<?php

namespace App\Http\Services\AppServices;

use App\Enums\EventStatus;
use App\Http\Services\Logger\EventActivityLogger;
use App\Models\Events;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes an event's status. Each action states where it may start from;
 * anything else is a 422 with a clear message instead of a silent change.
 */
class EventLifecycleService
{
    /** Make the invitation reachable. Needs the minimum a guest would see: name, date and type. */
    public function publish(Events $event, ?User $actor, string $stage = Events::STAGE_INVITATION, ?int $expectedVersionId = null): Events
    {
        // A published Save the date can be upgraded to the full invitation (same link); never the other way round.
        $upgrading = $event->status === EventStatus::PUBLISHED->value
            && $event->invitation_stage === Events::STAGE_SAVE_THE_DATE
            && $stage === Events::STAGE_INVITATION;

        if (! $upgrading) {
            $this->requireStatus($event, [EventStatus::DRAFT], 'Only a draft can be published.');
        }

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

        $invitations = app(InvitationService::class);
        $repin = $event->invitation_stage === Events::STAGE_SAVE_THE_DATE && $stage === Events::STAGE_INVITATION;
        // The version the organizer saw in her preview must be the one that goes out: an admin may have activated a
        // newer one in between (only checked when the app tells which one it showed).
        if ($expectedVersionId !== null && $stage === Events::STAGE_INVITATION) {
            $target = $event->template_version_id !== null && ! $repin
                ? $event->template_version_id
                : $invitations->templateFor($event)->current_version_id;
            if ($target !== $expectedVersionId) {
                throw new HttpResponseException(response()->json([
                    'message' => 'La plantilla se actualizó mientras la revisabas: revisa la vista previa antes de publicar.',
                    'code' => 'template_version_changed',
                    'currentVersionId' => $target,
                ], 409));
            }
        }

        $event->status = EventStatus::PUBLISHED->value;
        $event->invitation_stage = $stage;
        // The first publication is remembered forever: it is what locks the public address.
        $event->published_at ??= now();
        // The look of a published invitation never changes behind the organizer's back (template manager, A7). The Save
        // the date fixes nothing; going from it to the full invitation fixes the version again (D-3).
        $invitations->pinVersion($event, $stage, $repin);
        if ($stage === Events::STAGE_INVITATION) {
            // The choice is final now: what she set on the templates she left is no longer needed.
            $event->template_config_stash = null;
        }

        return $this->save($event, $actor, $upgrading ? 'event_stage_upgraded' : 'event_published');
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
        // Kept only to be read until it is purged, after its owner deleted her account.
        if ($event->isAccountArchived()) {
            throw ValidationException::withMessages(['status' => __('errors.account_deletion.event_archived')]);
        }
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
