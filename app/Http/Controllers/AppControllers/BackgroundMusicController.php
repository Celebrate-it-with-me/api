<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\BackgroundMusicRequest;
use App\Http\Resources\AppResources\BackgroundMusicResource;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Services\AppServices\BackgroundMusicServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BackgroundMusicController extends Controller
{
    
    public function __construct(private readonly BackgroundMusicServices  $suggestedMusicServices) {}
    
    /**
     * @param Request $request
     * @param Events $events
     * @return JsonResponse|BackgroundMusicResource
     */
    public function index(Request $request, Events $events): JsonResponse|BackgroundMusicResource
    {
        try {
            return BackgroundMusicResource::make($events->backgroundMusic);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Store a newly created resource in storage.
     * @param BackgroundMusicRequest $request
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
