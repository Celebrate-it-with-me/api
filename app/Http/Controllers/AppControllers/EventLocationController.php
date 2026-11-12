<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventLocationRequest;
use App\Http\Resources\AppResources\EventLocationResource;
use App\Models\EventLocation;
use App\Models\EventLocationImages;
use App\Models\Events;
use App\Models\PlacePhoto;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** The places of an event (ceremony, reception, others). Access is checked by `event.access` on the routes. */
class EventLocationController extends Controller
{
    public function index(Events $event)
    {
        $locations = EventLocation::query()
            ->with('eventLocationImages')
            ->where('event_id', $event->id)
            ->orderBy('id')
            ->get();

        return EventLocationResource::collection($locations);
    }

    public function show(Events $event, EventLocation $location): EventLocationResource
    {
        return EventLocationResource::make($location->load('eventLocationImages'));
    }

    public function store(StoreEventLocationRequest $request, Events $event): JsonResponse
    {
        $location = EventLocation::query()->create(['event_id' => $event->id, ...$request->place()]);

        return EventLocationResource::make($location->load('eventLocationImages'))->response()->setStatusCode(201);
    }

    public function update(StoreEventLocationRequest $request, Events $event, EventLocation $location): EventLocationResource
    {
        $location->update($request->place());

        return EventLocationResource::make($location->load('eventLocationImages'));
    }

    public function destroy(Events $event, EventLocation $location): JsonResponse
    {
        foreach ($location->eventLocationImages as $image) {
            $this->deleteFile($image);
        }
        $location->delete();

        return response()->json(['message' => 'Location removed.']);
    }

    /**
     * @throws ConnectionException
     */
    public function getLocationImages(Events $event, string $placeId): JsonResponse
    {
        if (! $placeId) {
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
                fn ($img) => [
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

        foreach (array_slice($photos ?? [], 0, 10) as $photo) {
            $photoReference = $photo['photo_reference'];
            $imageResponse = Http::withOptions(['allow_redirects' => true])
                ->get(config('services.google.url') . 'place/photo', [
                    'maxwidth' => 1024,
                    'photo_reference' => $photoReference,
                    'key' => config('services.google.map_key'),
                ]);

            if ($imageResponse->successful()) {
                $filename = 'locations/google/' . $event->id . '/' . $placeId . '/' . uniqid('photo_') . '.jpg';
                Storage::disk('public')->put($filename, $imageResponse->body(), [
                    'ContentType' => 'image/jpeg',
                ]);

                $placePhoto = PlacePhoto::query()->create([
                    'place_id' => $placeId,
                    'path' => Storage::disk('public')->url($filename),
                    'source' => 'google',
                ]);

                $saved->push([
                    'url' => $placePhoto->path,
                    'source' => $placePhoto->source,
                ]);
            }
        }

        return response()->json($saved);
    }

    /** Add pictures: own uploads and/or photos already fetched from Google. Six at most per place. */
    public function storeImages(Request $request, Events $event, EventLocation $location): EventLocationResource
    {
        $request->validate([
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpeg,png,webp', 'max:2048'],
            'googlePhotos' => ['nullable', 'array'],
            'googlePhotos.*' => ['string', 'max:500'],
        ]);

        $uploads = $request->file('images') ?? [];
        $google = collect($request->input('googlePhotos', []))->unique()->values();

        if ($location->eventLocationImages()->count() + count($uploads) + $google->count() > EventLocation::MAX_IMAGES) {
            throw ValidationException::withMessages(['images' => 'A place can have at most ' . EventLocation::MAX_IMAGES . ' pictures.']);
        }

        foreach ($uploads as $file) {
            $path = $file->store('locations/images', 'public');
            $location->eventLocationImages()->create(['path' => Storage::disk('public')->url($path), 'order' => 0, 'source' => 'uploaded']);
        }

        // Only photos this event fetched itself (stored under its own folder) can be attached.
        $own = Storage::disk('public')->url('locations/google/' . $event->id . '/');
        foreach ($google->filter(fn (string $url) => str_starts_with($url, $own)) as $url) {
            $location->eventLocationImages()->create(['path' => $url, 'order' => 0, 'source' => 'google']);
        }

        return EventLocationResource::make($location->load('eventLocationImages'));
    }

    public function destroyImage(Events $event, EventLocation $location, EventLocationImages $image): JsonResponse
    {
        abort_unless($image->event_location_id === $location->id, 404);

        $this->deleteFile($image);
        $image->delete();

        return response()->json(['message' => 'Picture removed.']);
    }

    private function deleteFile(EventLocationImages $image): void
    {
        if ($image->source !== 'uploaded') {
            return;
        }

        $relative = ltrim(str_replace(Storage::disk('public')->url(''), '', $image->path), '/');
        Storage::disk('public')->delete($relative);
    }
}
