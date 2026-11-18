<?php

/*
 * Invitation engine limits. The template manager (admin) validates every version against this file, so the public
 * renderer only ever receives content it knows how to draw: plain text, hex colors, fonts from a closed list.
 */
return [
    // Section types the renderer understands (cover and rsvp can never be disabled or removed).
    'section_types' => ['cover', 'itinerary', 'locations', 'dress_code', 'padrinos', 'gallery', 'rsvp', 'music', 'closing', 'comments', 'tables', 'suggested_music'],
    'required_sections' => ['cover', 'rsvp'],

    // Closed font lists: headings and body use the serif/sans group, the script slot the handwritten one.
    'fonts' => [
        'text' => ['Fraunces', 'Figtree', 'Playfair Display', 'Cormorant Garamond', 'Lora', 'Libre Baskerville', 'Montserrat', 'Josefin Sans'],
        'script' => ['Pinyon Script', 'Great Vibes', 'Dancing Script', 'Allura'],
    ],

    'color_keys' => ['surface', 'surfaceAlt', 'ink', 'inkSoft', 'accent', 'primary'],
    'themes' => ['classic'],

    // Engine level of the SPA the API talks to. A package declares `engineMin` and is only installed or activated
    // when it is <= this number. Raise it AFTER the SPA that implements the level is in production (deploy order:
    // app, then API); keep it lower in an environment whose SPA is older. The SPA falls back to Classic on its own
    // when its ENGINE is below a package's engineMin.
    'engine' => (int) env('INVITATION_ENGINE', 1),

    // Slugs of level 2 packages (their Vue components ship with the app); a level 2 zip is rejected otherwise.
    'level2_slugs' => [],

    // Template packages (docs/plans/template-package-spec.md): where the sanitized files live and how long a
    // validated upload waits to be installed.
    'packages' => [
        'disk' => 'template-packages',
        // Private disk where the uploaded zip and its staged thumbnail wait to be installed (never public). Must be shared
        // and persistent on a PaaS (several instances, redeploys): PACKAGES_STAGING_DISK=r2_private.
        'staging_disk' => env('PACKAGES_STAGING_DISK', 'local'),
        'stage_hours' => 2,
        // Valid uploads an admin may keep waiting to be installed (the oldest ones are discarded; W6-14).
        'max_stages_per_admin' => 5,
        // Origins that may read the package files from another origin (fonts, mask images). Defaults to the SPA domains.
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) (env('TEMPLATE_ASSETS_ALLOWED_ORIGINS') ?: env('ALLOWED_SPA_DOMAINS', '')))))),
    ],

    // The photo of the cover (D-8): what the organizer may upload and what is stored (WebP only, the original is not kept).
    'cover' => [
        'max_bytes' => 10 * 1024 * 1024,
        'max_side' => 8000,
        'max_pixels' => 40_000_000,
        'width' => 1440,
        'thumb_width' => 480,
        'quality' => 82,
    ],

    'limits' => [
        'sections' => 20,
        'text' => 1000,
        'title' => 200,
        'notes' => 1000,
        'presets' => 8,
        'preset_label' => 60,
        'json_bytes' => 65536,
    ],
];
