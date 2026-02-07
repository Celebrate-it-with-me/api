<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StorePublicSuggestedMusicRequest;
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

class PublicSuggestedMusicController extends Controller
{

    public function __construct(private readonly SuggestedMusicServices  $suggestedMusicServices) {}

    /**
     * Display a listing of suggested music (Public view - for guests)
     */
    public function index(Events $event): JsonResponse
    {
        try {
            $options = [
                'perPage' => request()->input('perPage', 10),
                'orderBy' => request()->input('orderBy', 'popular'), // Default to popular for guests
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
     * Store newly suggested music (Guest - requires access code)
     */
    public function store(StorePublicSuggestedMusicRequest $request, Events $event): JsonResponse
    {
        try {
            $music = $this->suggestedMusicServices->createByGuest(
                $event,
                $request->input('accessCode')
            );

            return response()->json([
                'message' => 'Song suggested successfully! Thank you for your contribution.',
                'data' => SuggestedMusicResource::make($music)
            ], 201);
        } catch (ValidationException $e) {
            // Handle duplicate song (409 Conflict)
            if ($e->status === 409) {
                return response()->json([
                    'message' => 'This song has already been suggested.',
                    'errors' => $e->errors(),
                    'canVote' => true, // Signal to frontend to redirect to voting
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
}
