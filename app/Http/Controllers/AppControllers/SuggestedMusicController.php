<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSaveTheDateRequest;
use App\Http\Requests\app\StoreSuggestedMusicRequest;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Resources\AppResources\SuggestedMusicResource;
use App\Http\Services\AppServices\SuggestedMusicServices;
use App\Models\Events;
use App\Models\SuggestedMusic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class SuggestedMusicController extends Controller
{
    
    public function __construct(private readonly SuggestedMusicServices  $suggestedMusicServices) {}
    
    /**
     * Display a listing of the resource.
     */
    public function index(Events $event)
    {
        try {
            return SuggestedMusicResource::collection($this->suggestedMusicServices->getSuggestedMusic($event))
                ->response()->getData(true);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Store a newly created resource in storage.
     * @param StoreSaveTheDateRequest $request
     * @param Events $event
     * @return SaveTheDateResource|JsonResponse
     */
    public function store(StoreSuggestedMusicRequest $request, Events $event): JsonResponse|SuggestedMusicResource
    {
        try {
            return SuggestedMusicResource::make($this->suggestedMusicServices->create($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SuggestedMusic $suggestedMusic): SuggestedMusicResource|JsonResponse
    {
        try {
            return SuggestedMusicResource::make($this->suggestedMusicServices->remove($suggestedMusic));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
