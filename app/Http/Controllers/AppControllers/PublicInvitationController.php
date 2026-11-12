<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\InvitationRsvpRequest;
use App\Http\Services\AppServices\InvitationService;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public invitation by event slug + guest code. Unknown slug, unpublished event
 * and wrong code all answer the same 404 so codes cannot be probed.
 */
class PublicInvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(Request $request, string $slug, string $code): JsonResponse
    {
        [$event, $guest] = $this->resolve($slug, $code);

        return response()->json([
            'data' => $this->invitations->build($event, $guest, $request->query('lang')),
        ]);
    }

    public function rsvp(InvitationRsvpRequest $request, string $slug, string $code): JsonResponse
    {
        [$event, $guest] = $this->resolve($slug, $code);

        $rsvp = $this->invitations->saveRsvp($event, $guest, $request->validated());

        return response()->json(['data' => $rsvp]);
    }

    /** @return array{0: Events, 1: Guest} */
    private function resolve(string $slug, string $code): array
    {
        $event = $this->invitations->findEvent($slug);
        $guest = $event ? $this->invitations->findGuest($event, $code) : null;

        abort_if(! $event || ! $guest, 404, 'Invitation not found.');

        return [$event, $guest];
    }
}
