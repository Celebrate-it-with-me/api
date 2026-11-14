<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Services\Spotify\SpotifyClient;
use App\Http\Services\Spotify\SpotifyUnavailable;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SpotifySearchController extends Controller
{
    public function __construct(private readonly SpotifyClient $spotify) {}

    public function __invoke(Request $request, Events $event): JsonResponse
    {
        $q = $request->query('q');
        $limit = $request->query('limit');
        $errors = [];

        if (! is_string($q) || trim($q) === '') {
            $errors['q'] = 'required';
        } elseif (mb_strlen($q) > 100) {
            $errors['q'] = 'too_long';
        }
        if ($limit !== null && $limit !== '' && (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 20)) {
            $errors['limit'] = 'invalid';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn ($code) => [$code], $errors));
        }

        try {
            $data = $this->spotify->searchTracks(trim($q), $limit === null || $limit === '' ? 5 : (int) $limit);
        } catch (SpotifyUnavailable) {
            return response()->json(['message' => __('errors.spotify.unavailable'), 'code' => 'spotify_unavailable'], 503);
        }

        return response()->json(['data' => $data]);
    }
}
