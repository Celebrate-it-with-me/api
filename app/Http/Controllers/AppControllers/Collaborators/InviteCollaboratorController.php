<?php

namespace App\Http\Controllers\AppControllers\Collaborators;

use App\Http\Controllers\Controller;
use App\Mail\InviteRegisteredUserMail;
use App\Mail\InviteUnregisteredUserMail;
use App\Models\EventCollaborationInvite;
use App\Models\Events;
use App\Models\EventUserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Throwable;

class InviteCollaboratorController extends Controller
{
    /** Roles that can be given to a collaborator; `owner` is never granted here. */
    private const ASSIGNABLE_ROLES = ['editor', 'viewer'];

    /**
     * The people of the event (owner first) and its open invitations, in the shape the Colaboradores screen reads.
     */
    public function index(Events $event): JsonResponse
    {
        $members = EventUserRole::query()
            ->where('event_id', $event->id)
            ->with(['user', 'role'])
            ->get()
            ->filter(fn (EventUserRole $row) => $row->user !== null)
            ->map(fn (EventUserRole $row) => $this->memberRow($row));

        $invites = EventCollaborationInvite::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['pending', 'expired', 'declined'])
            ->orderBy('id')
            ->get()
            ->map(fn (EventCollaborationInvite $invite) => $this->inviteRow($invite));

        return response()->json(['data' => $members->concat($invites)->values()]);
    }

    /**
     * Invite someone by email. An existing account joins right away (and gets the "you were added" mail);
     * a new email gets a pending invitation valid for 7 days.
     */
    public function invite(Request $request, Events $event): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'role' => ['required', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);
        $email = strtolower(trim($data['email']));

        $existingUser = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existingUser && EventUserRole::query()->where('event_id', $event->id)->where('user_id', $existingUser->id)->exists()) {
            return response()->json(['message' => __('errors.collaborators.already_member')], 422);
        }

        $pending = EventCollaborationInvite::query()->where('event_id', $event->id)
            ->whereRaw('lower(email) = ?', [$email])->where('status', 'pending')->exists();
        if ($pending) {
            return response()->json(['message' => __('errors.collaborators.pending_invite')], 422);
        }

        $invite = EventCollaborationInvite::query()->create([
            'event_id' => $event->id,
            'email' => $existingUser?->email ?? $email,
            'role' => $data['role'],
            'token' => EventCollaborationInvite::generateToken(),
            'status' => $existingUser ? 'accepted' : 'pending',
            'invited_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);
        $invite->forceFill(['sent_at' => now()])->save();

        if ($existingUser) {
            $member = EventUserRole::query()->create([
                'event_id' => $event->id,
                'user_id' => $existingUser->id,
                'role_id' => Role::query()->where('name', $data['role'])->value('id'),
            ]);
            Mail::to($existingUser->email)->send(new InviteRegisteredUserMail($invite));

            return response()->json(['message' => 'User has been added as a collaborator.', 'data' => $this->memberRow($member->load(['user', 'role']))]);
        }

        Mail::to($invite->email)->send(new InviteUnregisteredUserMail($invite));

        return response()->json(['message' => 'Invitation sent successfully.', 'data' => $this->inviteRow($invite)]);
    }

    /** Changes the role of a member. The owner's role is not touched here (it moves by ownership transfer, in support). */
    public function changeRole(Request $request, Events $event, int $user): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'string', Rule::in(self::ASSIGNABLE_ROLES)]]);

        $member = EventUserRole::query()->where('event_id', $event->id)->where('user_id', $user)->with(['user', 'role'])->first();
        abort_if(! $member, 404);

        if ($member->role?->name === 'owner') {
            return response()->json(['message' => __('errors.collaborators.owner_role_fixed')], 403);
        }

        $member->update(['role_id' => Role::query()->where('name', $data['role'])->value('id')]);

        return response()->json(['message' => 'Role updated.', 'data' => $this->memberRow($member->load('role'))]);
    }

    /** Removes someone's access. The owner cannot be removed (so nobody removes themselves by mistake either). */
    public function destroy(Events $event, int $user): JsonResponse
    {
        $member = EventUserRole::query()->where('event_id', $event->id)->where('user_id', $user)->with('role')->first();
        abort_if(! $member, 404);

        if ($member->role?->name === 'owner') {
            return response()->json(['message' => __('errors.collaborators.owner_not_removable')], 403);
        }

        DB::transaction(function () use ($member, $event) {
            $member->delete();
            User::query()->where('id', $member->user_id)->where('last_active_event_id', $event->id)->update(['last_active_event_id' => null]);
        });

        return response()->json(['message' => 'Collaborator removed.']);
    }

    /** Sends the invitation mail again and renews its 7 days. Pending and expired ones only. */
    public function resend(Events $event, int $id): JsonResponse
    {
        $invite = $this->openInvite($event, $id, ['pending', 'expired']);
        if (! $invite) {
            return response()->json(['message' => __('errors.collaborators.resend_not_allowed')], 422);
        }

        $invite->forceFill(['status' => 'pending', 'expires_at' => now()->addDays(7), 'sent_at' => now(), 'resend_count' => ($invite->resend_count ?? 0) + 1])->save();

        $registered = User::query()->whereRaw('lower(email) = ?', [strtolower($invite->email)])->exists();
        Mail::to($invite->email)->send($registered ? new InviteRegisteredUserMail($invite) : new InviteUnregisteredUserMail($invite));

        return response()->json(['message' => 'Invitation resent.', 'data' => $this->inviteRow($invite->refresh())]);
    }

    /** Cancels an invitation that nobody answered (its link stops working). */
    public function cancel(Events $event, int $id): JsonResponse
    {
        $invite = $this->openInvite($event, $id, ['pending', 'expired', 'declined']);
        abort_if(! $invite, 404);

        $invite->delete();

        return response()->json(['message' => 'Invitation cancelled.']);
    }

    private function openInvite(Events $event, int $id, array $statuses): ?EventCollaborationInvite
    {
        $invite = EventCollaborationInvite::query()->where('event_id', $event->id)->where('id', $id)->first();
        abort_if(! $invite || $invite->status === 'accepted', 404);

        return in_array($invite->status, $statuses, true) ? $invite : null;
    }

    private function memberRow(EventUserRole $member): array
    {
        return [
            'id' => $member->id,
            'type' => 'member',
            'user_id' => $member->user_id,
            'name' => $member->user->name,
            'email' => $member->user->email,
            'avatar_url' => $member->user->avatar_url,
            'role' => $member->role?->name ?? 'viewer',
            'status' => 'active',
        ];
    }

    private function inviteRow(EventCollaborationInvite $invite): array
    {
        $status = $invite->status === 'pending' && $invite->isExpired() ? 'expired' : $invite->status;

        return [
            'id' => $invite->id,
            'type' => 'invite',
            'user_id' => null,
            'name' => $invite->email,
            'email' => $invite->email,
            'avatar_url' => '',
            'role' => $invite->role,
            'status' => $status,
        ];
    }

    /**
     * Display an event collaboration invitation based on a given token.
     *
     * This method retrieves an invitation by its token and checks its validity.
     * If the invitation is invalid or expired, a 404 JSON response is returned.
     * Otherwise, relevant invitation details such as email, event, and role are returned.
     *
     * @param  string  $token  The invitation token used to locate the specific invitation.
     * @return JsonResponse A JSON response containing invitation details or an error message.
     */
    public function checkToken(Events $event, string $token): JsonResponse
    {
        $invite = EventCollaborationInvite::query()
            ->where('event_id', $event->id)
            ->where('token', $token)
            ->valid()
            ->first();

        if (! $invite) {
            return response()->json(['message' => __('errors.collaborators.invitation_invalid')], 422);
        }

        return response()->json([
            'id' => $invite->id,
            'email' => $invite->email,
            'event' => $invite->event->only(['id', 'event_name']),
            'role' => $invite->role,
            'status' => $invite->status,
            'token' => $invite->token,
        ]);
    }

    /**
     * Handles the decline of an event collaboration invite.
     *
     * This function validates an event collaboration invite token and marks the invite as declined.
     * It returns a JSON response indicating the success or failure of the operation.
     *
     * @param  Events  $event  The event for which the invite is being declined.
     * @param  string  $token  The token associated with the invite.
     * @return JsonResponse A JSON response indicating the success or failure of the operation.
     */
    public function declineInvite(Events $event, string $token): JsonResponse
    {
        $invite = EventCollaborationInvite::query()
            ->where('event_id', $event->id)
            ->where('token', $token)
            ->valid()
            ->first();

        if (! $invite) {
            return response()->json(['message' => __('errors.collaborators.invitation_invalid')], 422);
        }

        $invite->markAsDeclined();

        return response()->json([
            'id' => $invite->id,
            'email' => $invite->email,
            'event' => $invite->event->only(['id', 'event_name']),
            'role' => $invite->role,
            'status' => $invite->status,
            'token' => $invite->token,
        ]);
    }

    /**
     * Handles the acceptance of an event collaboration invite.
     *
     * This function validates an event collaboration invite token, ensures the authenticated user is
     * authorized to accept the invite, associates the user as a collaborator to the event, and
     * updates the invite status as accepted.
     *
     * @param  Request  $request  The HTTP request instance containing the invite token.
     * @param  $token
     * @return JsonResponse A JSON response indicating the success or failure of the operation.
     */
    public function accept(Events $event, string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $invite = EventCollaborationInvite::query()
            ->where('event_id', $event->id)
            ->where('id', $id)
            ->first();

        // Only a pending invitation that has not expired can be accepted (and only once: accepting closes it).
        if (! $invite || $invite->status !== 'pending' || ($invite->expires_at && $invite->expires_at->isPast()) || ! in_array($invite->role, self::ASSIGNABLE_ROLES, true)) {
            return response()->json(['message' => __('errors.collaborators.invitation_invalid')], 404);
        }

        if (strtolower((string) $invite->email) !== strtolower((string) $user->email)) {
            return response()->json(['message' => __('errors.collaborators.not_your_invitation')], 400);
        }

        DB::beginTransaction();

        try {

            EventUserRole::query()->firstOrCreate([
                'event_id' => $event->id,
                'user_id' => $user->id,
            ], [
                'role_id' => Role::query()->where('name', $invite->role)->first()->id,
            ]);

            $invite->update([
                'status' => 'accepted',
            ]);

            $user->last_active_event_id = $event->id;
            $user->save();

            DB::commit();

            return response()->json([
                'message' => 'Invitation accepted.',
                'event' => $event->only(['id', 'event_name']),
                'role' => $invite->role,
            ]);

        } catch (Throwable $th) {
            DB::rollBack();
            report($th);

            return response()->json(['message' => __('errors.collaborators.accept_failed')], 500);
        }
    }

    /**
     * Retrieve and display event collaboration invitations based on provided tokens.
     *
     * This method handles a batch retrieval of event collaboration invitations using the provided tokens.
     * It checks each token's validity and retrieves relevant invitations. If no valid invitations are found,
     * a 404 JSON response with an error message is returned. Otherwise, a transformed collection of invitation details
     * is returned in the response.
     *
     * @param  Request  $request  The incoming request containing the tokens needed to find invitations.
     * @return JsonResponse A JSON response containing a collection of transformed invitation data or an error message.
     */
    public function eventTokens(Request $request): JsonResponse
    {
        $data = $request->validate(['tokens' => ['required', 'array', 'max:20'], 'tokens.*' => ['required', 'uuid']]);
        $invites = EventCollaborationInvite::query()
            ->whereIn('token', $data['tokens'])
            ->valid()
            ->get();

        if (! $invites) {
            return response()->json(['message' => __('errors.collaborators.invitation_invalid')], 404);
        }

        $invitesTransformed = $invites->map(function ($invite) {
            return [
                'id' => $invite->id,
                'email' => $invite->email,
                'event' => $invite->event->only(['id', 'event_name']),
                'role' => $invite->role,
                'status' => $invite->status,
                'token' => $invite->token,
            ];
        });

        return response()->json($invitesTransformed);
    }
}
