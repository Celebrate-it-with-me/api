<?php

namespace App\Http\Controllers\AppControllers\SaveTheDate;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\UpsertSaveTheDateRequest;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Services\AppServices\SaveTheDateServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;

class SaveTheDateController extends Controller
{
    public function __construct(
        private readonly SaveTheDateServices $service
    ) {}
    
    public function show(Events $event): JsonResponse
    {
        $saveTheDate = $this->service->getForEvent($event);
        
        return response()->json([
            'data' => $saveTheDate ? new SaveTheDateResource($saveTheDate) : null,
        ]);
    }
    
    public function upsert(UpsertSaveTheDateRequest $request, Events $event): JsonResponse
    {
        $saveTheDate = $this->service->upsertForEvent(
            $event,
            $request->validated()
        );
        
        return response()->json([
            'data' => new SaveTheDateResource($saveTheDate),
        ], 200);
    }
    
    public function destroy(Events $event): JsonResponse
    {
        $deleted = $this->service->deleteForEvent($event);
        
        return response()->json([
            'deleted' => $deleted,
        ], $deleted ? 200 : 404);
    }
}
