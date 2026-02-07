<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSuggestedVoteMusicRequest;
use App\Http\Resources\AppResources\SuggestedMusicVoteResource;
use App\Http\Services\AppServices\SuggestedMusicVoteServices;
use App\Models\Events;
use App\Models\SuggestedMusic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class SuggestedMusicVoteController extends Controller
{
    public function __construct(
        private readonly SuggestedMusicVoteServices $suggestedMusicVoteServices
    ) {}

    /**
     * Get available votes for guest (using accessCode)
     */
    public function getAvailableVotes(Request $request, Events $event): JsonResponse
    {
        try {
            $accessCode = $request->input('accessCode');

            if (!$accessCode) {
                return response()->json([
                    'message' => 'Access code is required.',
                    'data' => []
                ], 422);
            }

            $votesData = $this->suggestedMusicVoteServices->getAvailableVotes(
                $accessCode,
                $event->id
            );

            return response()->json([
                'data' => $votesData
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Store or update vote (with toggle logic)
     */
    public function storeOrUpdate(
        StoreSuggestedVoteMusicRequest $request,
        Events $event,
        SuggestedMusic $suggestedMusic
    ): JsonResponse
    {
        try {
            $accessCode = $request->input('accessCode');

            if (!$accessCode) {
                return response()->json([
                    'message' => 'Access code is required.',
                    'data' => []
                ], 422);
            }

            $result = $this->suggestedMusicVoteServices->storeOrUpdate(
                $suggestedMusic,
                $accessCode
            );

            // Build response message based on action
            $messages = [
                'created' => 'Vote recorded!',
                'updated' => 'Vote updated!',
                'removed' => 'Vote removed!',
            ];

            return response()->json([
                'message' => $messages[$result['action']],
                'data' => [
                    'action' => $result['action'],
                    'vote' => $result['vote'] ? SuggestedMusicVoteResource::make($result['vote']) : null,
                    'votesRemaining' => $result['votesRemaining'],
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Get user's current vote on a song (using accessCode)
     */
    public function getUserVote(
        Request $request,
        Events $event,
        SuggestedMusic $suggestedMusic
    ): JsonResponse
    {
        try {
            $accessCode = $request->input('accessCode');

            if (!$accessCode) {
                return response()->json([
                    'message' => 'Access code is required.',
                    'data' => []
                ], 422);
            }

            $vote = $this->suggestedMusicVoteServices->getUserVote($suggestedMusic, $accessCode);

            return response()->json([
                'data' => $vote ? SuggestedMusicVoteResource::make($vote) : null
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }
}
