<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Suggested Music Configuration
    |--------------------------------------------------------------------------
    */

    // Maximum songs a guest can suggest
    'max_suggestions_per_guest' => env('MUSIC_MAX_SUGGESTIONS_PER_GUEST', 5),

    // Supported platforms
    'platforms' => [
        'spotify' => [
            'enabled' => true,
            'name' => 'Spotify',
        ],
        'youtube' => [
            'enabled' => false, // Future implementation
            'name' => 'YouTube',
        ],
    ],

    // Vote types
    'vote_types' => ['up', 'down'],
];
