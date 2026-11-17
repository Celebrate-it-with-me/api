<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\InvitationSongRequest;
use App\Http\Requests\app\InvitationSongSearchRequest;
use App\Http\Services\AppServices\Invitation\InvitationSongException;
use App\Http\Services\AppServices\Invitation\InvitationSongs;
use App\Http\Services\AppServices\InvitationService;
use App\Http\Services\Spotify\SpotifyUnavailable;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Suggested music of the public invitation, by event slug + guest code (same credentials and same uniform 404 as the
 * RSVP). The module (`suggested_music`) and the rate limits are route middleware.
 */
class PublicInvitationSongController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly InvitationSongs $songs,
    ) {}

    public function index(Request $request, string $slug, string $code): JsonResponse
    {
        [$event, $guest] = $this->resolve($slug, $code);
        $orderBy = $request->query('orderBy', 'popular');

        if (! in_array($orderBy, ['popular', 'recent'], true)) {
            return response()->json(['message' => 'invalid', 'errors' => ['orderBy' => ['invalid']]], 422);
        }

        $result = $this->songs->page($event, $guest, $orderBy);
        $page = $result['page'];

        return response()->json([
            'data' => $page->getCollection()->map(fn ($song) => $this->songs->present($event, $guest, $song, $result['usePreview']))->values(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
                'votesTotal' => $result['votesTotal'],
                'votesRemaining' => $result['votesRemaining'],
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function search(InvitationSongSearchRequest $request, string $slug, string $code): JsonResponse
    {
        [$event] = $this->resolve($slug, $code);

        if (! $this->songs->canWrite($event)) {
            return $this->readOnly();
        }

        try {
            $data = $this->songs->search($event, $request->validated()['q'], isset($request->validated()['limit']) ? (int) $request->validated()['limit'] : null);
        } catch (SpotifyUnavailable) {
            return response()->json(['message' => __('errors.spotify.unavailable'), 'code' => 'spotify_unavailable'], 503);
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function store(InvitationSongRequest $request, string $slug, string $code): JsonResponse
    {
        [$event, $guest] = $this->resolve($slug, $code);

        if (! $this->songs->canWrite($event)) {
            return $this->readOnly();
        }

        try {
            $song = $this->songs->suggest($event, $guest, $request->validated());
        } catch (InvitationSongException $e) {
            return $this->fail($e);
        }

        return response()->json(['data' => $this->songs->present($event, $guest, $song)], 201);
    }

    public function destroy(string $slug, string $code, int $song): Response|JsonResponse
    {
        [$event, $guest] = $this->resolve($slug, $code);

        if (! $this->songs->canWrite($event)) {
            return $this->readOnly();
        }

        $this->songs->remove($event, $guest, $song);

        return response()->noContent();
    }

    public function vote(Request $request, string $slug, string $code, int $song): JsonResponse
    {
        [$event, $guest] = $this->resolve($slug, $code);

        $vote = $request->input('vote');
        if (! is_bool($vote)) {
            return response()->json(['message' => 'required', 'errors' => ['vote' => [$vote === null ? 'required' : 'invalid']]], 422);
        }
        if (! $this->songs->canWrite($event)) {
            return $this->readOnly();
        }

        try {
            $result = $this->songs->vote($event, $guest, $song, $vote);
        } catch (InvitationSongException $e) {
            return $this->fail($e);
        }

        $fresh = $result['song']->loadCount(['musicVotes as up_votes' => fn ($q) => $q->where('vote_type', 'up')])
            ->loadExists(['musicVotes as voted_by_me' => fn ($q) => $q->where('guest_id', $guest->id)]);

        return response()->json([
            'data' => $this->songs->present($event, $guest, $fresh),
            'meta' => $this->songs->budget($guest),
        ]);
    }

    /** @return array{0: Events, 1: Guest} */
    private function resolve(string $slug, string $code): array
    {
        $event = $this->invitations->findEvent($slug);
        $guest = $event ? $this->invitations->findGuest($event, $code) : null;

        abort_if(! $event || ! $guest, 404, 'Invitation not found.');

        return [$event, $guest];
    }

    private function readOnly(): JsonResponse
    {
        return response()->json(['message' => __('errors.permission.module_unavailable'), 'code' => 'read_only'], 403);
    }

    private function fail(InvitationSongException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->codeName,
            'code' => $e->codeName,
            'errors' => [$e->field => [$e->codeName]],
        ], $e->status);
    }
}
