<?php

/*
 * Invitation engine limits. The template manager (admin) validates every version against this file, so the public
 * renderer only ever receives content it knows how to draw: plain text, hex colors, fonts from a closed list.
 */
return [
    // Section types the renderer understands (cover and rsvp can never be disabled or removed).
    'section_types' => ['cover', 'itinerary', 'locations', 'dress_code', 'padrinos', 'rsvp', 'closing'],
    'required_sections' => ['cover', 'rsvp'],

    // Closed font lists: headings and body use the serif/sans group, the script slot the handwritten one.
    'fonts' => [
        'text' => ['Fraunces', 'Figtree', 'Playfair Display', 'Cormorant Garamond', 'Lora', 'Libre Baskerville', 'Montserrat', 'Josefin Sans'],
        'script' => ['Pinyon Script', 'Great Vibes', 'Dancing Script', 'Allura'],
    ],

    'color_keys' => ['surface', 'surfaceAlt', 'ink', 'inkSoft', 'accent', 'primary'],
    'themes' => ['classic'],

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
