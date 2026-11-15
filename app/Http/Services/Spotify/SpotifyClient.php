<?php

namespace App\Http\Services\Spotify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side Spotify catalogue search (client-credentials flow). The client secret never leaves the API.
 * Any failure (no credentials, timeout, non-2xx) becomes SpotifyUnavailable without exposing why.
 */
class SpotifyClient
{
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    private const SEARCH_URL = 'https://api.spotify.com/v1/search';

    private const CACHE_KEY = 'spotify.client_credentials_token';

    private const TIMEOUT_SECONDS = 5;

    /** @return array{tracks: array{items: array<int, array<string, mixed>>}} */
    public function searchTracks(string $query, int $limit): array
    {
        try {
            $response = Http::withToken($this->token())
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::SEARCH_URL, ['q' => $query, 'type' => 'track', 'limit' => $limit]);

            if ($response->status() === 401) {
                Cache::forget(self::CACHE_KEY);
            }
            if (! $response->successful()) {
                throw new SpotifyUnavailable;
            }

            $items = $response->json('tracks.items');
        } catch (SpotifyUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            throw new SpotifyUnavailable;
        }

        return ['tracks' => ['items' => array_values(array_map($this->onlyTrackFields(...), is_array($items) ? $items : []))]];
    }

    private function token(): string
    {
        $id = config('services.spotify.client_id');
        $secret = config('services.spotify.client_secret');
        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            throw new SpotifyUnavailable;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->withBasicAuth($id, $secret)->timeout(self::TIMEOUT_SECONDS)
            ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);
        $token = $response->successful() ? $response->json('access_token') : null;
        if (! is_string($token) || $token === '') {
            throw new SpotifyUnavailable;
        }

        $expiresIn = (int) $response->json('expires_in', 3600);
        Cache::put(self::CACHE_KEY, $token, now()->addSeconds(max(1, $expiresIn - 60)));

        return $token;
    }

    /** @param array<string, mixed> $track */
    private function onlyTrackFields(array $track): array
    {
        return [
            'id' => $track['id'] ?? null,
            'name' => $track['name'] ?? null,
            'uri' => $track['uri'] ?? null,
            'duration_ms' => $track['duration_ms'] ?? null,
            'preview_url' => $track['preview_url'] ?? null,
            'external_urls' => ['spotify' => $track['external_urls']['spotify'] ?? null],
            'artists' => array_map(fn ($a) => ['name' => $a['name'] ?? null], $track['artists'] ?? []),
            'album' => [
                'name' => $track['album']['name'] ?? null,
                'images' => array_map(fn ($i) => ['url' => $i['url'] ?? null], $track['album']['images'] ?? []),
            ],
        ];
    }
}
