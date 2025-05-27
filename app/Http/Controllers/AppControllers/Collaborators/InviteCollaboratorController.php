<?php

namespace App\Http\Controllers\AppControllers\Collaborators;

use App\Http\Controllers\Controller;
use App\Models\EventCollaborationInvite;
use App\Models\Events;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InviteCollaboratorController extends Controller
{
    /**
     * @param Request $request
     * @param Events $event
     * @return JsonResponse
     */
    public function invite(Request $request, Events $event): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'role' => 'required|string',
        ]);
        
        if($data['email'] === $event->organizer->email) {
            return response()->json(['message' => 'You cannot invite the event owner.'], 400);
        }
        
        $existingUser = User::query()->where('email', $data['email'])->first();
        
        if ($existingUser) {
            if ($event->collaborators()->where('user_id', $existingUser->id)->exists()) {
                return response()->json(['message' => 'User is already a collaborator.'], 400);
            }
            
            $event->collaborators()->attach($existingUser->id, ['role' => $data['role']]);
            
            return response()->json(['message' => 'User has been added as a collaborator.']);
        }
        
        $token = EventCollaborationInvite::generateToken();
        
        $invite = EventCollaborationInvite::query()->create([
            'event_id' => $event->id,
            'email' => $data['email'],
            'role' => $data['role'],
            'token' => $token,
            'status' => 'pending',
            'invited_by_user_id' => auth()->id(),
            'expires_at' => now()->addDays(7),
        ]);
        
        
        
        return response()->json([
            'message' => 'Invitation sent successfully.',
            'invite' => $invite,
        ]);
    }
    
    /**
     * Display an event collaboration invitation based on a given token.
     *
     * This method retrieves an invitation by its token and checks its validity.
     * If the invitation is invalid or expired, a 404 JSON response is returned.
     * Otherwise, relevant invitation details such as email, event, and role are returned.
     *
     * @param string $token The invitation token used to locate the specific invitation.
     * @return JsonResponse A JSON response containing invitation details or an error message.
     */
    public function checkToken(Events $event, string $token): JsonResponse
    {
        $invite = EventCollaborationInvite::query()
            ->where('token', $token)
            ->first();
        
        if (!$invite) {
            return response()->json(['message' => 'Invalid or expired invitation.'], 422);
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
     * @param Events $event The event for which the invite is being declined.
     * @param string $token The token associated with the invite.
     * @return JsonResponse A JSON response indicating the success or failure of the operation.
     */
    public function declineInvite(Events $event, string $token): JsonResponse
    {
        $invite = EventCollaborationInvite::query()
            ->where('token', $token)
            ->valid()
            ->first();
        
        if (!$invite) {
            return response()->json(['message' => 'Invalid or expired invitation.'], 422);
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
     * @param Request $request The HTTP request instance containing the invite token.
     * @param $token
     * @return JsonResponse A JSON response indicating the success or failure of the operation.
     */
    public function accept(Request $request, $token): JsonResponse
    {
        $invite = EventCollaborationInvite::query()
            ->where('token', $token)
            ->valid()
            ->first();
        
        if (!$invite) {
            return response()->json(['message' => 'Invalid or expired invitation.'], 404);
        }
        
        if (auth()->user()->email !== $invite->email) {
            return response()->json(['message' => 'You are not authorized to accept this invitation.'], 400);
        }
        
        $invite->event->collaborators()->attach(auth()->user()->id, ['role' => $invite->role]);
        
        $invite->markAsAccepted();
        
        return response()->json(['message' => 'You have been added as a collaborator.']);
    }
    
    /**
     * Retrieve and display event collaboration invitations based on provided tokens.
     *
     * This method handles a batch retrieval of event collaboration invitations using the provided tokens.
     * It checks each token's validity and retrieves relevant invitations. If no valid invitations are found,
     * a 404 JSON response with an error message is returned. Otherwise, a transformed collection of invitation details
     * is returned in the response.
     *
     * @param Request $request The incoming request containing the tokens needed to find invitations.
     * @return JsonResponse A JSON response containing a collection of transformed invitation data or an error message.
     */
    public function eventTokens(Request $request): JsonResponse
    {
        $invites = EventCollaborationInvite::query()
            ->whereIn('token', $request->tokens)
            ->valid()
            ->get();
        
        if (!$invites) {
            return response()->json(['message' => 'Invalid or expired invitation.'], 404);
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
