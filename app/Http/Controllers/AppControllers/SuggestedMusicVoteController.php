<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSuggestedVoteMusicRequest;
use App\Http\Resources\AppResources\SuggestedMusicVoteResource;
use App\Http\Services\AppServices\SuggestedMusicVoteServices;
use App\Models\SuggestedMusic;
use Illuminate\Http\JsonResponse;
use Throwable;

class SuggestedMusicVoteController extends Controller
{
    public function __construct(private readonly SuggestedMusicVoteServices $suggestedMusicVoteServices) {}

    /**
     * Display a listing of the resource.
     */
    public function index(SuggestedMusic $suggestedMusic): JsonResponse|SuggestedMusicVoteResource
    {
        try {
            return SuggestedMusicVoteResource::make(
                $this->suggestedMusicVoteServices->getMusicVote($suggestedMusic)
            );
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Handles the storing or updating of a suggested music vote.
     *
     * @param  StoreSuggestedVoteMusicRequest  $request  The request instance containing validation rules and data.
     * @param  SuggestedMusic  $suggestedMusic  The suggested music instance to be processed.
     * @return SuggestedMusicVoteResource|JsonResponse On success, returns a resource representation of the suggested music vote.
     *                                                 On failure, returns a JSON response with the error message and an empty data array.
     */
    public function storeOrUpdate(StoreSuggestedVoteMusicRequest $request, SuggestedMusic $suggestedMusic): SuggestedMusicVoteResource|JsonResponse
    {
        try {
            return SuggestedMusicVoteResource::make(
                $this->suggestedMusicVoteServices->storeOrUpdate($suggestedMusic)
            );
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
