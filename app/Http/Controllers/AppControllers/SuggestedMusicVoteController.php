<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSaveTheDateRequest;
use App\Http\Requests\app\StoreSuggestedMusicRequest;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Resources\AppResources\SuggestedMusicVoteResource;
use App\Models\Events;
use App\Models\SuggestedMusic;
use App\Models\SuggestedMusicVote;
use Illuminate\Http\JsonResponse;
use Throwable;

class SuggestedMusicVoteController extends Controller
{
    
    public function __construct(private readonly SuggestedMusicVoteServices  $suggestedMusicVoteServices) {}
    
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
     * Store a newly created resource in storage.
     * @param StoreSuggestedMusicRequest $request
     * @param Events $event
     * @return SaveTheDateResource|JsonResponse
     */
    public function store(StoreSuggestedMusicRequest $request, Events $event): JsonResponse|SuggestedMusicVoteResource
    {
        try {
            return SuggestedMusicVoteResource::make($this->suggestedMusicVoteServices->create($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    public function destroy(suggestedMusicVote $suggestedMusicVote): JsonResponse|SuggestedMusicVoteResource
    {
        try {
            return SuggestedMusicVoteResource::make($this->suggestedMusicVoteServices->remove($suggestedMusicVote));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
