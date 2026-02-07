<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSweetMemoriesConfigRequest;
use App\Http\Requests\app\UpdateSweetMemoriesConfigRequest;
use App\Http\Resources\AppResources\SweetMemoriesConfigResource;
use App\Http\Services\AppServices\SweetMemoriesConfigServices;
use App\Models\Events;
use App\Models\SweetMemoriesConfig;
use Illuminate\Http\JsonResponse;
use Throwable;

class SweetMemoriesConfigController extends Controller
{
    
    private SweetMemoriesConfigServices $sweetMemoriesConfigServices;
    
    public function __construct(SweetMemoriesConfigServices $sweetMemoriesConfigServices)
    {
        $this->sweetMemoriesConfigServices = $sweetMemoriesConfigServices;
    }
    
    /**
     * Display a listing of the resource.
     */
    public function index(Events $event): JsonResponse|SweetMemoriesConfigResource
    {
        try {
            $config = $this->sweetMemoriesConfigServices->getSweetMemoriesConfig($event);
            
            if ($config !== null) {
                return SweetMemoriesConfigResource::make($config);
            }
            
            return response()->json([
                'message' => 'No sweet memories configuration exists for the given event',
                'data' => []
            ], 404);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(). ' '.$th->getFile(). ' ' . $th->getLine() , 'data' => []], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSweetMemoriesConfigRequest $request, Events $event): JsonResponse|SweetMemoriesConfigResource
    {
        try {
            return SweetMemoriesConfigResource::make($this->sweetMemoriesConfigServices->create($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(SweetMemoriesConfig $sweetMemoriesConfig): SweetMemoriesConfigResource|JsonResponse
    {
        try {
            return SweetMemoriesConfigResource::make($sweetMemoriesConfig);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        UpdateSweetMemoriesConfigRequest $request,
        Events $event,
        SweetMemoriesConfig $sweetMemoriesConfig
    ): SweetMemoriesConfigResource|JsonResponse
    {
        try {
            return SweetMemoriesConfigResource::make(
                $this->sweetMemoriesConfigServices->update($sweetMemoriesConfig)
            );
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SweetMemoriesConfig $sweetMemoriesConfig): SweetMemoriesConfigResource|JsonResponse
    {
        try {
            return SweetMemoriesConfigResource::make($this->sweetMemoriesConfigServices->destroy($sweetMemoriesConfig));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
