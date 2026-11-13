<?php

namespace App\Http\Services\Admin\EventActions;

use App\Exceptions\PlanLimitExceeded;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\AppServices\EventLifecycleService;
use App\Mail\ConfirmAccountEmail;
use App\Mail\InviteRegisteredUserMail;
use App\Mail\InviteUnregisteredUserMail;
use App\Mail\OwnershipTransferredMail;
use App\Mail\WelcomeEmail;
use App\Models\EventActivity;
use App\Models\EventCollaborationInvite;
use App\Models\Events;
use App\Models\EventUserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * What staff (superadmin) do to an event from the Acciones tab. Status changes go through the existing
 * EventLifecycleService (so plan limits and transitions are the organizer's), run as the system and flagged
 * `byStaff` in the event activity; every action is audited with its reason.
 */
class StaffEventActions
{
    private const ACTIVITY = ['publish' => 'event_published', 'unpublish' => 'event_unpublished', 'archive' => 'event_archived', 'restore' => 'event_restored'];

    public function __construct(private readonly EventLifecycleService $lifecycle, private readonly AdminAudit $audit) {}

    /** @throws PlanLimitExceeded */
    public function lifecycle(Events $event, string $action, string $reason, User $actor, ?string $stage = null): Events
    {
        return DB::transaction(function () use ($event, $action, $reason, $actor, $stage) {
            $before = $this->state($event);
            $updated = match ($action) {
                'publish' => $this->lifecycle->publish($event, null, $stage ?: Events::STAGE_INVITATION),
                'unpublish' => $this->lifecycle->unpublish($event, null),
                'archive' => $this->lifecycle->archive($event, null),
                'restore' => $this->lifecycle->restore($event, null),
            };

            // The activity row was just written without an actor: mark it as staff work (the app shows it as "soporte").
            $row = EventActivity::where('event_id', $event->id)->whereIn('type', [self::ACTIVITY[$action], 'event_stage_upgraded'])->latest('id')->first();
            $row?->update(['payload' => ($row->payload ?? []) + ['by_staff' => true]]);

            $this->audit->record("event.{$action}", $updated, ['reason' => $reason, 'actor' => $actor, 'before' => $before, 'after' => $this->state($updated)]);

            return $updated;
        });
    }

    /** Resends the account confirmation when the owner has not confirmed, the welcome email otherwise. */
    public function resendOwnerEmail(Events $event, string $reason, User $actor): string
    {
        $owner = $event->owner_user;
        if (! $owner) {
            throw ValidationException::withMessages(['event' => 'Este evento no tiene dueña.']);
        }

        if ($owner->email_verified_at === null) {
            $signed = URL::temporarySignedRoute('auth.confirm.email', now()->addDay(), ['user' => $owner->id]);
            Mail::to($owner->email)->send(new ConfirmAccountEmail($owner, config('app.frontend_app.url') . '/confirm-email?confirm=' . urlencode($signed)));
            $kind = 'confirm_account';
        } else {
            Mail::to($owner->email)->send(new WelcomeEmail($owner));
            $kind = 'welcome';
        }

        $this->audit->record('event.resend_owner_email', $event, ['reason' => $reason, 'actor' => $actor, 'user_id' => $owner->id, 'after' => ['email' => $kind]]);

        return $kind;
    }

    public function resendCollaboratorInvite(Events $event, EventCollaborationInvite $invite, string $reason, User $actor): EventCollaborationInvite
    {
        if ($invite->event_id !== $event->id) {
            abort(404);
        }
        if (! $invite->status || $invite->status !== 'pending' || $invite->isExpired()) {
            throw ValidationException::withMessages(['invite' => 'Solo se puede reenviar una invitación pendiente y vigente.']);
        }

        $registered = User::where('email', $invite->email)->exists();
        Mail::to($invite->email)->send($registered ? new InviteRegisteredUserMail($invite) : new InviteUnregisteredUserMail($invite));

        $invite->sent_at = now();
        $invite->resend_count = ($invite->resend_count ?? 0) + 1;
        $invite->save();

        $this->audit->record('event.resend_collaborator_invite', $event, ['reason' => $reason, 'actor' => $actor, 'after' => ['inviteId' => $invite->id, 'email' => $invite->email]]);

        return $invite;
    }

    public function changeSlug(Events $event, string $slug, string $reason, User $actor): Events
    {
        $old = $event->custom_url_slug;
        // Staff may change a locked address; that is the point of this action.
        $event->custom_url_slug = $slug;
        $event->save();

        $this->audit->record('event.change_slug', $event, ['reason' => $reason, 'actor' => $actor, 'before' => ['slug' => $old], 'after' => ['slug' => $slug]]);

        return $event->refresh();
    }

    /** Moves ownership to an existing, active account; the previous owner stays as editor. */
    public function transfer(Events $event, User $newOwner, string $reason, User $actor): array
    {
        $ownerRole = Role::where('name', 'owner')->firstOrFail();
        $editorRole = Role::where('name', 'editor')->firstOrFail();

        $previous = DB::transaction(function () use ($event, $newOwner, $ownerRole, $editorRole) {
            $current = EventUserRole::where('event_id', $event->id)->where('role_id', $ownerRole->id)->lockForUpdate()->first();
            if ($current?->user_id === $newOwner->id) {
                throw ValidationException::withMessages(['newOwnerEmail' => 'Esa usuaria ya es la dueña del evento.']);
            }

            // The new owner may already be an editor or viewer: one role per person in the event.
            EventUserRole::where('event_id', $event->id)->where('user_id', $newOwner->id)->delete();
            EventUserRole::create(['event_id' => $event->id, 'user_id' => $newOwner->id, 'role_id' => $ownerRole->id]);
            $current?->update(['role_id' => $editorRole->id]);

            return $current?->user;
        });

        $this->audit->record('event.transfer_owner', $event, [
            'reason' => $reason, 'actor' => $actor, 'user_id' => $newOwner->id,
            'before' => ['ownerId' => $previous?->id], 'after' => ['ownerId' => $newOwner->id, 'previousOwnerRole' => $previous ? 'editor' : null],
        ]);

        // Mails go after the commit: a failed delivery must not undo the transfer.
        foreach (array_filter([[$newOwner, 'new'], $previous ? [$previous, 'previous'] : null]) as [$user, $side]) {
            try {
                Mail::to($user->email)->send(new OwnershipTransferredMail($event, $user, $side));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ['previousOwner' => $previous, 'newOwner' => $newOwner];
    }

    private function state(Events $e): array
    {
        return ['status' => $e->status, 'stage' => $e->invitation_stage, 'publishedAt' => $e->published_at?->toIso8601String()];
    }
}
