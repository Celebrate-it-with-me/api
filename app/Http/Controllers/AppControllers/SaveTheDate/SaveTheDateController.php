<?php

namespace App\Http\Controllers\AppControllers\SaveTheDate;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\UpsertSaveTheDateRequest;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Services\AppServices\SaveTheDateServices;
use App\Models\Events;
use App\Models\SaveTheDate;
use Illuminate\Http\JsonResponse;

class SaveTheDateController extends Controller
{
    public function __construct(
        private readonly SaveTheDateServices $service
    ) {}
    
    public function show(Events $event): JsonResponse
    {
        // An event that has not set anything up still answers the defaults.
        $saveTheDate = $this->service->getForEvent($event) ?? new SaveTheDate(['show_countdown' => true, 'use_add_to_calendar' => true]);

        return response()->json(['data' => new SaveTheDateResource($saveTheDate)]);
    }
    
    public function upsert(UpsertSaveTheDateRequest $request, Events $event): JsonResponse
    {
        $saveTheDate = $this->service->upsertForEvent(
            $event,
            $request->settings()
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
