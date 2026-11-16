<?php

return [

    /*
    | gzip of text responses by the app itself (see CompressResponse). Turn it off when the PaaS router / CDN in front
    | already compresses (HTTP_COMPRESSION=false); they also do brotli, which the app does not.
    */
    'compression' => [
        'enabled' => (bool) env('HTTP_COMPRESSION', true),
        'level' => (int) env('HTTP_COMPRESSION_LEVEL', 5),
    ],

    /*
    | Public invitation (GET invitation/{slug}/{code}). The payload is per guest (name, RSVP), so the browser may keep it
    | but must revalidate (ETag -> 304); shared caches must not (`private`). The part that is the same for every guest
    | is cached server side (InvitationPayloadCache).
    */
    'invitation' => [
        'cache_ttl' => (int) env('INVITATION_CACHE_TTL', 900),
    ],
];
