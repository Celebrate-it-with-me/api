<?php

namespace App\Http\Controllers;

use App\Http\Requests\app\StoreSaveTheDateRequest;
use App\Http\Requests\app\UpdateSaveTheDateRequest;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Services\AppServices\SaveTheDateServices;
use App\Models\Events;
use App\Models\SaveTheDate;
use Illuminate\Http\JsonResponse;
use Throwable;

class SaveTheDateController extends Controller
{
    
    public function __construct(private readonly SaveTheDateServices  $saveTheDateServices) {}
    
    /**
     * Display a listing of the resource.
     */
    public function index(Events $event): JsonResponse|SaveTheDateResource
    {
        try {
            return SaveTheDateResource::make($this->saveTheDateServices->getEventSTD($event));
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
    public function store(StoreSaveTheDateRequest $request, Events $event): JsonResponse|SaveTheDateResource
    {
        try {
            return SaveTheDateResource::make($this->saveTheDateServices->createEventSTD($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(SaveTheDate $saveTheDate)
    {
        //
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSaveTheDateRequest $request, SaveTheDate $saveTheDate)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SaveTheDate $saveTheDate)
    {
        //
    }
}
