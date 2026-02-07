<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSuggestedMusicConfigRequest;
use App\Http\Requests\app\UpdateSuggestedMusicConfigRequest;
use App\Http\Resources\AppResources\SuggestedMusicConfigResource;
use App\Http\Services\AppServices\SuggestedMusicConfigServices;
use App\Models\Events;
use App\Models\SuggestedMusicConfig;
use Illuminate\Http\JsonResponse;
use Throwable;

class SuggestedMusicConfigController extends Controller
{
    
    private SuggestedMusicConfigServices $suggestedMusicConfigServices;
    
    public function __construct(SuggestedMusicConfigServices $suggestedMusicConfigServices)
    {
        $this->suggestedMusicConfigServices = $suggestedMusicConfigServices;
    }
    
    /**
     * Display a listing of the resource.
     */
    public function index(Events $event): JsonResponse|SuggestedMusicConfigResource
    {
        try {
            $config = $this->suggestedMusicConfigServices->getSuggestedMusicConfig($event);
            
            if ($config !== null) {
                return SuggestedMusicConfigResource::make($config);
            }
            
            return response()->json([
                'message' => 'No suggested music configuration exists for the given event',
                'data' => []
            ], 404);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(). ' '.$th->getFile(). ' ' . $th->getLine() , 'data' => []], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSuggestedMusicConfigRequest $request, Events $event): JsonResponse|SuggestedMusicConfigResource
    {
        try {
            return SuggestedMusicConfigResource::make($this->suggestedMusicConfigServices->create($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(SuggestedMusicConfig $suggestedMusicConfig): SuggestedMusicConfigResource|JsonResponse
    {
        try {
            return SuggestedMusicConfigResource::make($suggestedMusicConfig);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSuggestedMusicConfigRequest $request, SuggestedMusicConfig $suggestedMusicConfig): SuggestedMusicConfigResource|JsonResponse
    {
        try {
            return SuggestedMusicConfigResource::make($this->suggestedMusicConfigServices->update($suggestedMusicConfig));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SuggestedMusicConfig $suggestedMusicConfig): SuggestedMusicConfigResource|JsonResponse
    {
        try {
            return SuggestedMusicConfigResource::make($this->suggestedMusicConfigServices->destroy($suggestedMusicConfig));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
