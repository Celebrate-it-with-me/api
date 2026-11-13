<?php

return [
    // Life of an impersonation token. Fixed: it is never renewed (see RefreshTokenExpirationIfActive).
    'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 45),

    // What the user's own activity feed and the app banner call the staff, instead of a name.
    'staff_label' => 'Soporte',
];
