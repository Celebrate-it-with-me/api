<?php

namespace App\Http\Services\Spotify;

use RuntimeException;

/** Spotify cannot be used right now (no credentials, timeout, error). Carries no detail on purpose. */
class SpotifyUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('spotify_unavailable');
    }
}
