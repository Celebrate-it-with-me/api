<?php

namespace App\Support\TemplatePackage;

/**
 * Closed catalogs of the HTML dialect (model A): system blocks `<q-*>` with their props, template variables with
 * their filters, and declarative behaviors (`data-*`). The renderer of the app must implement exactly the same
 * tables; the server and the app both reject anything outside them.
 */
class BlockCatalog
{
    public const SECTION_TYPES = [
        'cover', 'itinerary', 'locations', 'dress_code', 'padrinos', 'gallery', 'rsvp', 'music', 'closing', 'countdown', 'save_the_date',
    ];

    /**
     * The 19 system blocks. Prop types: ['enum', [values]], ['int', min, max], ['bool'], ['pattern', regex]. `max` =
     * occurrences per package file; `scope` = where it may appear (`any`, `layout`, `section:<type>`). All blocks are
     * leaves in v1 (no children). `frame` is shared by the blocks that draw a section: `full` = heading, ornament and
     * panel of the engine, `no-title` = without the heading (the package writes its own), `bare` = only the content.
     */
    public const BLOCKS = [
        'rsvp' => ['props' => ['frame' => self::FRAME, 'variant' => ['enum', ['card', 'paper', 'inline']]], 'max' => 1, 'scope' => 'section:rsvp'],
        'gallery' => ['props' => ['frame' => self::FRAME, 'layout' => ['enum', ['grid', 'carousel']], 'columns' => ['int', 2, 4], 'max' => ['int', 1, 40], 'captions' => ['bool']], 'max' => 1, 'scope' => 'any'],
        'music' => ['props' => ['variant' => ['enum', ['bar', 'button']]], 'max' => 1, 'scope' => 'any'],
        'countdown' => ['props' => ['frame' => self::FRAME, 'format' => ['enum', ['full', 'compact']]], 'max' => 1, 'scope' => 'any'],
        'locations' => ['props' => ['frame' => self::FRAME, 'variant' => ['enum', ['list', 'cards']], 'maps' => ['bool']], 'max' => 1, 'scope' => 'any'],
        'itinerary' => ['props' => ['frame' => self::FRAME, 'variant' => ['enum', ['timeline', 'list']]], 'max' => 1, 'scope' => 'any'],
        'dress-code' => ['props' => ['frame' => self::FRAME, 'variant' => ['enum', ['plain', 'swatches']]], 'max' => 1, 'scope' => 'any'],
        'padrinos' => ['props' => ['frame' => self::FRAME, 'variant' => ['enum', ['list', 'grid']]], 'max' => 1, 'scope' => 'any'],
        'date' => ['props' => ['format' => ['enum', ['columns', 'ring', 'inline']], 'weekday' => ['enum', ['short', 'long', 'none']], 'month' => ['enum', ['short', 'long']], 'time' => ['bool']], 'max' => 3, 'scope' => 'any'],
        'names' => ['props' => ['tag' => ['enum', ['h1', 'h2', 'p', 'div']], 'script' => ['bool']], 'max' => 2, 'scope' => 'any'],
        'cover-photo' => ['props' => ['aspect' => ['enum', ['4:5', '3:4', '16:10', '1:1']], 'fit' => ['enum', ['cover', 'contain']], 'focus' => ['pattern', '/^\d{1,3}% \d{1,3}%$/'], 'priority' => ['bool']], 'max' => 1, 'scope' => 'any'],
        'language-switch' => ['props' => ['variant' => ['enum', ['buttons', 'dropdown']]], 'max' => 1, 'scope' => 'any'],
        'cta' => ['props' => ['action' => ['enum', ['confirm', 'calendar', 'locations']], 'variant' => ['enum', ['fill', 'outline']]], 'max' => 4, 'scope' => 'any'],
        'seats' => ['props' => [], 'max' => 1, 'scope' => 'any'],
        'address' => ['props' => ['line' => ['enum', ['name', 'full']]], 'max' => 1, 'scope' => 'any'],
        'guest' => ['props' => ['greeting' => ['bool']], 'max' => 1, 'scope' => 'any'],
        'closing' => ['props' => ['frame' => self::FRAME, 'variant' => ['enum', ['band', 'plain']]], 'max' => 1, 'scope' => 'any'],
        'save-the-date' => ['props' => ['frame' => self::FRAME, 'calendar' => ['bool']], 'max' => 1, 'scope' => 'any'],
        'sections' => ['props' => [], 'max' => 1, 'scope' => 'layout'],
    ];

    private const FRAME = ['enum', ['full', 'no-title', 'bare']];

    /**
     * Template variables. Each lists the contexts it exists in and its filters. No expressions, no arguments.
     * Dates are rendered by the system in the event timezone and the visitor language.
     */
    public const VARS = [
        'event.name' => ['string', 'any'],
        'event.description' => ['string', 'any'],
        'event.date' => ['date', 'any'],
        'event.time' => ['string', 'any'],
        'guest.name' => ['string', 'any'],
        'guest.first_name' => ['string', 'any'],
        'rsvp.seats' => ['int', 'any'],
        'rsvp.deadline' => ['date', 'any'],
        'section.title' => ['string', 'section'],
        'section.text' => ['string', 'section'],
        'section.position' => ['int', 'section'],
        'theme.slug' => ['string', 'any'],
        'theme.preset' => ['string', 'any'],
    ];

    /** `t.<key>` variables: interface strings of the package (`strings.json`), validated against its own keys. */
    public const STRING_KEY = '/^[a-z][a-zA-Z0-9]{0,31}$/';

    public const FILTERS = [
        'string' => ['upper', 'lower', 'capitalize'],
        'date' => ['long', 'short', 'weekday', 'weekday_short', 'day', 'month', 'month_short', 'year', 'upper_month', 'time'],
        'int' => [],
    ];

    /**
     * Declarative behaviors. The renderer implements them, honours prefers-reduced-motion (static final state) and
     * never lets the package run code. `exclusive` groups cannot share an element; `forbidQ` = no `<q-*>` inside.
     */
    public const BEHAVIORS = [
        'data-reveal' => ['values' => ['fade', 'rise', 'zoom', 'slide-left', 'slide-right'], 'group' => 'motion', 'forbidQ' => false],
        'data-reveal-delay' => ['values' => ['0', '100', '200', '300', '400', '600', '800'], 'requires' => 'data-reveal'],
        'data-parallax' => ['values' => ['subtle', 'soft', 'medium'], 'group' => 'motion', 'forbidQ' => true, 'tags' => ['div', 'span', 'img', 'figure']],
        'data-carousel' => ['values' => ['snap'], 'tags' => ['ul', 'div'], 'forbidQ' => true],
        'data-carousel-controls' => ['values' => ['arrows', 'dots', 'none'], 'requires' => 'data-carousel'],
        'data-carousel-peek' => ['values' => ['on', 'off'], 'requires' => 'data-carousel'],
    ];

    public static function filtersFor(string $varType): array
    {
        return self::FILTERS[$varType] ?? [];
    }
}
