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
    public function show(string $token): JsonResponse
    {
        $invite = EventCollaborationInvite::query()->where('token', $token)->valid()->first();
        
        if (!$invite) {
            return response()->json(['message' => 'Invalid or expired invitation.'], 404);
        }
        
        return response()->json([
            'email' => $invite->email,
            'event' => $invite->event->only(['id', 'name']),
            'role' => $invite->role,
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
    
    
}
