<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\InvitationCommentRequest;
use App\Http\Services\AppServices\Invitation\InvitationComments;
use App\Http\Services\AppServices\InvitationService;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;

/**
 * Comments of the public invitation, by event slug + guest code (same credentials and same uniform 404 as the RSVP).
 * The module (`comments`) and the rate limits are route middleware.
 */
class PublicInvitationCommentController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly InvitationComments $comments,
    ) {}

    public function index(string $slug, string $code): JsonResponse
    {
        [$event] = $this->resolve($slug, $code);

        $page = $this->comments->page($event);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($comment) => InvitationComments::present($comment))->values(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(InvitationCommentRequest $request, string $slug, string $code): JsonResponse
    {
        [$event, $guest] = $this->resolve($slug, $code);

        if (! $this->comments->canWrite($event)) {
            return response()->json(['message' => __('errors.permission.module_unavailable'), 'code' => 'read_only'], 403);
        }

        $comment = $this->comments->create($event, $guest, $request->validated()['comment']);

        return response()->json(['data' => InvitationComments::present($comment)], 201);
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
