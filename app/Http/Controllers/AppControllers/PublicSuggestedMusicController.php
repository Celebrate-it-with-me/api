<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\DestroyPublicSuggestedMusicRequest;
use App\Http\Requests\app\StorePublicSuggestedMusicRequest;
use App\Http\Requests\app\StoreSaveTheDateRequest;
use App\Http\Requests\app\StoreSuggestedMusicRequest;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Resources\AppResources\SuggestedMusicResource;
use App\Http\Services\AppServices\SuggestedMusicServices;
use App\Models\Events;
use App\Models\Guest;
use App\Models\SuggestedMusic;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
                'orderBy' => request()->input('orderBy', 'popular'),
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
    
    /**
     * Delete a song suggestion made by a guest for the specified event.
     *
     * @param DestroyPublicSuggestedMusicRequest $request Incoming request containing access code and song ID.
     * @param Events $event The event associated with the song suggestion.
     *
     * @return JsonResponse
     *
     * @throws ModelNotFoundException If the guest or song suggestion cannot be found.
     * @throws AuthorizationException If the guest is not authorized to delete the song.
     * @throws Throwable For any unexpected errors during the process.
     */
    public function destroy(DestroyPublicSuggestedMusicRequest $request, Events $event)
    {
        try {
            $guestRequesting = Guest::where('code', $request->input('accessCode'))->firstOrFail();
            
            $suggestedMusic = SuggestedMusic::where('event_id', $event->id)
                ->where('id', $request->input('songId'))
                ->firstOrFail();
            
            if ($suggestedMusic->suggestedBy instanceof User) {
                return response()->json([
                    'message' => 'You are not authorized to delete this song.',
                ], 422);
            }
            
            if ($suggestedMusic->suggestedBy->id !== $guestRequesting->id) {
                return response()->json([
                    'message' => 'You are not authorized to delete this song.',
                ], 403);
            }
            
            $suggestedMusic->delete();
            
            return response()->json([
                'message' => 'Song deleted successfully.',
            ], 200);
            
        } catch (Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }
}
