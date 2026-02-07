<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreSaveTheDateRequest;
use App\Http\Requests\app\StoreSuggestedMusicRequest;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Resources\AppResources\SuggestedMusicResource;
use App\Http\Services\AppServices\SuggestedMusicServices;
use App\Models\Events;
use App\Models\SuggestedMusic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Throwable;

class SuggestedMusicController extends Controller
{

    public function __construct(private readonly SuggestedMusicServices  $suggestedMusicServices) {}

    /**
     * Display a listing of suggested music (Organizer view)
     */
    public function index(Events $event): JsonResponse
    {
        try {
            $options = [
                'perPage' => request()->input('perPage', 10),
                'orderBy' => request()->input('orderBy', 'recent'), // 'recent' or 'popular'
            ];

            $music = $this->suggestedMusicServices->getSuggestedMusic($event, $options);

            return response()->json([
                'data' => SuggestedMusicResource::collection($music->items()),
                'meta' => [
                    'current_page' => $music->currentPage(),
                    'total' => $music->total(),
                    'per_page' => $music->perPage(),
                    'last_page' => $music->lastPage(),
                ]
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Store newly suggested music (Organizer - no access code needed)
     */
    public function store(StoreSuggestedMusicRequest $request, Events $event): JsonResponse
    {
        try {
            $music = $this->suggestedMusicServices->createByOrganizer($event, $request->user());

            return response()->json([
                'message' => 'Song added successfully!',
                'data' => SuggestedMusicResource::make($music)
            ], 201);
        } catch (ValidationException $e) {
            // Handle duplicate song (409 Conflict)
            if ($e->status === 409) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 409);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Remove suggested music (Organizer only)
     */
    public function destroy(Events $event, SuggestedMusic $suggestedMusic): JsonResponse
    {
        try {
            $removed = $this->suggestedMusicServices->remove($suggestedMusic);

            return response()->json([
                'message' => 'Song removed successfully!',
                'data' => SuggestedMusicResource::make($removed)
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }
}
