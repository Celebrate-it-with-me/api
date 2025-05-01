<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventLocationRequest;
use App\Http\Requests\UpdateEventLocationRequest;
use App\Http\Resources\AppResources\EventLocationResource;
use App\Models\EventLocation;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EventLocationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Events $event)
    {
        $perPage = $request->input('perPage', 10);
        $page = $request->input('page', 1);
        $searchValue = $request->input('searchValue');
        
        try {
            $locations = EventLocation::query()
                ->where('event_id', $event->id)
                ->when($searchValue, function ($query) use ($searchValue) {
                    $query->where(function ($q) use ($searchValue) {
                        $q->where('name', 'like', "%{$searchValue}%")
                            ->orWhere('address', 'like', "%{$searchValue}%")
                            ->orWhere('city', 'like', "%{$searchValue}%");
                    });
                })
                ->paginate($perPage, ['*'], 'page', $page);
            
            return EventLocationResource::collection($locations)
                ->response()->getData(true);
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            return response()->json([
                'error' => 'An error occurred while fetching event locations.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventLocationRequest $request, Events $event): JsonResponse | EventLocationResource
    {
        $validated = $request->validated();
        
        try {
            DB::beginTransaction();
            $eventLocation = EventLocation::create([
                'event_id' => $event->id,
                'name' => $validated['name'],
                'address' => $validated['address'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'zip_code' => $validated['zipCode'] ?? null,
                'country' => $validated['country'],
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'is_default' => $validated['isDefault'] ?? false,
            ]);
            
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('locations/images', 'public');
                    
                    $eventLocation->eventLocationImages()->create([
                        'path' =>  Storage::disk('public')->url($path),
                        'caption' => null,
                        'order' => 0,
                        'source' => 'uploaded',
                    ]);
                }
            }
            
            if ($request->filled('google_photos')) {
                $googlePhotos = json_decode($request->input('google_photos'), true);
                
                foreach ($googlePhotos as $url) {
                    $eventLocation->eventLocationImages()->create([
                        'path' => $url,
                        'caption' => null,
                        'order' => 0,
                        'source' => 'google',
                    ]);
                }
            }
            
            DB::commit();
            
            return EventLocationResource::make($eventLocation);
        } catch (Throwable $th) {
            DB::rollBack();
            Log::error('que fallo', [$th->getMessage(), $th->getLine(), $th->getFile()]);
            return response()->json([
                'error' => 'An error occurred while creating event location.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(EventLocation $location): JsonResponse|EventLocationResource
    {
        try {
            return EventLocationResource::make($location);
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            return response()->json([
                'error' => 'An error occurred while fetching event location.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEventLocationRequest $request, Events $event, EventLocation $eventLocation)
    {
        try {
            $eventLocation->update($request->validated());
            return EventLocationResource::make($eventLocation);
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            return response()->json([
                'error' => 'An error occurred while updating event location.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Events $event, EventLocation $location): JsonResponse
    {
        try {
            $location->delete();
            return response()->json([
                'message' => 'Event location deleted successfully.',
            ], 200);
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            return response()->json([
                'error' => 'An error occurred while deleting event location.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }
}
