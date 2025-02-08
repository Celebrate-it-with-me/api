<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreEventsRequest;
use App\Http\Requests\app\UpdateEventsRequest;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Resources\AppResources\TemplateResource;
use App\Http\Services\AppServices\EventsServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class TemplateController extends Controller
{
    public function getEventData(Events $event, string $guestCode): JsonResponse|TemplateResource
    {
        try {
            return new TemplateResource($event, $guestCode);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => []], 500);
        }
    }
    
}
