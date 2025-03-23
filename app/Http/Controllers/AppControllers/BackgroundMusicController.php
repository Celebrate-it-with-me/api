<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\BackgroundMusicRequest;
use App\Http\Requests\app\StoreSaveTheDateRequest;
use App\Http\Requests\app\StoreSuggestedMusicRequest;
use App\Http\Resources\AppResources\BackgroundMusicResource;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Resources\AppResources\SuggestedMusicResource;
use App\Http\Services\AppServices\BackgroundMusicServices;
use App\Http\Services\AppServices\SuggestedMusicServices;
use App\Models\Events;
use App\Models\SuggestedMusic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class BackgroundMusicController extends Controller
{
    
    public function __construct(private readonly BackgroundMusicServices  $suggestedMusicServices) {}
    
    /**
     * Store a newly created resource in storage.
     * @param Events $event
     * @return SaveTheDateResource|JsonResponse
     */
    public function store(BackgroundMusicRequest $request, Events $event): JsonResponse|BackgroundMusicResource
    {
        try {
            return BackgroundMusicResource::make($this->suggestedMusicServices->create($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
