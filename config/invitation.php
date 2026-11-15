<?php

/*
 * Invitation engine limits. The template manager (admin) validates every version against this file, so the public
 * renderer only ever receives content it knows how to draw: plain text, hex colors, fonts from a closed list.
 */
return [
    // Section types the renderer understands (cover and rsvp can never be disabled or removed).
    'section_types' => ['cover', 'itinerary', 'locations', 'dress_code', 'padrinos', 'gallery', 'rsvp', 'music', 'closing'],
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
        'stage_hours' => 24,
        // Origins that may read the package files from another origin (fonts, mask images). Defaults to the SPA domains.
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) (env('TEMPLATE_ASSETS_ALLOWED_ORIGINS') ?: env('ALLOWED_SPA_DOMAINS', '')))))),
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
