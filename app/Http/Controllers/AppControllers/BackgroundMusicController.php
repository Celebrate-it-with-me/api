<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\BackgroundMusicRequest;
use App\Http\Resources\AppResources\BackgroundMusicResource;
use App\Http\Services\AppServices\BackgroundMusicServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BackgroundMusicController extends Controller
{
    public function __construct(private readonly BackgroundMusicServices $music) {}

    public function index(Request $request, Events $event): JsonResponse|BackgroundMusicResource
    {
        try {
            // No background music yet is a normal answer, not an error.
            return $event->backgroundMusic
                ? BackgroundMusicResource::make($event->backgroundMusic)
                : response()->json(['data' => null]);
        } catch (Throwable $th) {
            report($th);

            return response()->json(['message' => 'Something went wrong. Try again.', 'data' => []], 500);
        }
    }

    /** One song per event: 201 when it is the first, 200 when it replaced the previous one. */
    public function store(BackgroundMusicRequest $request, Events $event): JsonResponse
    {
        try {
            [$music, $created] = $this->music->save($event, $request->file('songFile'), $request->safe()->except('songFile'));

            return BackgroundMusicResource::make($music)->response()->setStatusCode($created ? 201 : 200);
        } catch (Throwable $th) {
            report($th);

            return response()->json(['message' => 'Something went wrong. Try again.', 'data' => []], 500);
        }
    }

    public function destroy(Request $request, Events $event): JsonResponse
    {
        if (! $this->music->delete($event)) {
            return response()->json(['message' => 'This event has no background music.', 'code' => 'background_music_not_found'], 404);
        }

        return response()->json(['data' => null]);
    }
}
