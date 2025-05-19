<?php

namespace App\Http\Controllers\AppControllers;

use App\Events\GooglePlacePhotosQueued;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventLocationRequest;
use App\Http\Requests\UpdateEventLocationRequest;
use App\Http\Resources\AppResources\EventLocationResource;
use App\Models\EventLocation;
use App\Models\Events;
use App\Models\PlacePhoto;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
     * @throws ConnectionException
     */
    public function getLocationImages(Events $event, string $placeId): JsonResponse
    {
        if (!$placeId) {
            return response()->json([
                'error' => 'Place ID is required.',
            ], 422);
        }
        
        $existingPlacePhotos = PlacePhoto::query()
            ->where('place_id', $placeId)
            ->where('source', 'google')
            ->get();
        
        if ($existingPlacePhotos->count()) {
            return response()->json($existingPlacePhotos->map(
                fn($img) => [
                    'url' => $img->path,
                    'source' => 'google',
                ]
            ));
        }
        
        $response = Http::withOptions(['allow_redirects' => true])
            ->acceptJson()
            ->get(config('services.google.url') . 'place/details/json', [
                'place_id' => $placeId,
                'fields' => 'photos',
                'key' => config('services.google.map_key'),
            ]);
        
        Log::info('Google API response', [$response->json()]);
        
        $photos = $response->json('result.photos');
        $saved = collect();
        
        foreach (array_slice($photos, 0, 10) as $photo) {
            $photoReference = $photo['photo_reference'];
            $imageResponse = Http::withOptions(['allow_redirects' => true])
                ->get(config('services.google.url') . 'place/photo', [
                    'maxwidth' => 1024,
                    'photo_reference' => $photoReference,
                    'key' => config('services.google.map_key'),
                ]);
            
            if ($imageResponse->successful()) {
                $filename = 'locations/google/'. $event->id . '/' . $placeId . '/' . uniqid('photo_') . '.jpg';
                Storage::disk('public')->put($filename, $imageResponse->body());
                
                $placePhoto = PlacePhoto::query()->create([
                    'place_id' => $placeId,
                    'path' => Storage::disk('public')->url($filename),
                    'source' => 'google',
                ]);
                
                
                
                $saved->push([
                        'url' => $placePhoto->path,
                        'source' => $placePhoto->source
                    ]
                );
            }
        }
        
        return response()->json($saved);
    }
    
    public function storeImages(Request $request, Events $event, EventLocation $location): JsonResponse
    {
        try {
            DB::beginTransaction();
            
            $savedPhotos = collect();
            
            // Handle uploaded images
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('locations/images', 'public');
                    $savedPhotos->push([
                        'url' => Storage::disk('public')->url($path),
                        'source' => 'uploaded',
                    ]);
                    
                    $location->eventLocationImages()->create([
                        'path' => Storage::disk('public')->url($path),
                        'caption' => null,
                        'order' => 0,
                        'source' => 'uploaded',
                    ]);
                }
            }
            
            // Handle Google photos
            if ($request->filled('googlePhotos')) {
                $googlePhotos = json_decode($request->input('googlePhotos'), true);
                
                foreach ($googlePhotos as $photo) {
                    if (!empty($photo['url'])) {
                        $location->eventLocationImages()->create([
                            'path' => $photo['url'],
                            'caption' => null,
                            'order' => 0,
                            'source' => 'google',
                        ]);
                        
                        $savedPhotos->push([
                            'url' => $photo['url'],
                            'source' => 'google',
                        ]);
                    }
                }
            }
            
            DB::commit();
            
            return response()->json($savedPhotos);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Failed to upload event location images', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            return response()->json([
                'message' => 'Error uploading images.',
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
            $eventLocation = EventLocation::query()->create([
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
            
            DB::commit();
            
            return EventLocationResource::make($eventLocation);
        } catch (Throwable $th) {
            DB::rollBack();
            Log::error('What fails', [$th->getMessage(), $th->getLine(), $th->getFile()]);
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
