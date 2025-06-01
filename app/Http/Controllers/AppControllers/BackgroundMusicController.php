<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\BackgroundMusicEditRequest;
use App\Http\Requests\app\BackgroundMusicRequest;
use App\Http\Resources\AppResources\BackgroundMusicResource;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Services\AppServices\BackgroundMusicServices;
use App\Models\BackgroundMusic;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackgroundMusicController extends Controller
{
    public function __construct(private readonly BackgroundMusicServices $suggestedMusicServices) {}

    /**
     * @param  Events  $events
     */
    public function index(Request $request, Events $event): JsonResponse|BackgroundMusicResource
    {
        try {
            return BackgroundMusicResource::make($event->backgroundMusic);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
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

    /**
     * Update the specified resource in storage.
     *
     * @param  BackgroundMusicEditRequest  $request
     */
    public function update(Request $request, BackgroundMusic $backgroundMusic): JsonResponse|BackgroundMusicResource
    {
        Log::info('Checking request', ['request' => $request->all()]);

        try {
            return BackgroundMusicResource::make($this->suggestedMusicServices->update($backgroundMusic));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
