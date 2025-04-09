<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSweetMemoriesImageRequest;
use App\Http\Resources\AppResources\SweetMemoriesConfigResource;
use App\Http\Services\AppServices\SweetMemoriesImageServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Throwable;

class SweetMemoriesImageController extends Controller
{
    private SweetMemoriesImageServices $sweetMemoriesImageServices;
    
    public function __construct(SweetMemoriesImageServices $sweetMemoriesImageServices)
    {
        $this->sweetMemoriesImageServices = $sweetMemoriesImageServices;
    }
    
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSweetMemoriesImageRequest $request, Events $event): JsonResponse|SweetMemoriesConfigResource
    {
        try {
            return SweetMemoriesImageResource::make($this->sweetMemoriesImageServices->create($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
