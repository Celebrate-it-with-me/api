<?php

namespace App\Http\Services\Admin\Templates;

use App\Http\Services\AppServices\InvitationService;

/**
 * The public invitation payload (same shape as `data` of GET /app/invitation/{slug}/{code}) for a version that is
 * being edited, filled with sample data written here. It never reads real events or guests.
 */
class TemplatePreview
{
    public function __construct(private readonly InvitationService $invitation) {}

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    public function build(array $sections, array $theme, string $locale, string $eventType, ?array $package = null, string $state = 'invitation'): array
    {
        $wedding = $eventType === 'wedding';
        $sample = $this->sample($wedding);
        $saveTheDate = $state === 'save_the_date';

        if ($saveTheDate) {
            $sections = $this->saveTheDateSections();
        }
        if ($state === 'no_photo') {
            $sections = array_map(fn (array $s) => $s['type'] === 'cover' ? array_replace_recursive($s, ['settings' => ['image' => null]]) : $s, $sections);
        }

        $resolved = collect($sections)
            ->filter(fn (array $s) => $s['enabled'] ?? false)
            ->sortBy(fn (array $s) => $s['order'] ?? 99)
            ->map(fn (array $s) => [
                'type' => $s['type'],
                'title' => $this->invitation->localized($s['title'] ?? null, $locale),
                'text' => $this->invitation->localized($s['text'] ?? null, $locale),
                'data' => $this->data($s, $sample) ?: (object) [],
            ] + $this->invitation->settingsEntry($s, $locale))
            ->values()
            ->all();

        $resolved = $this->invitation->attachPackage($resolved, $package['sections'] ?? []);

        return [
            'event' => [
                'name' => $sample['name'],
                'typeSlug' => $wedding ? 'wedding' : 'quinceanera',
                'startsAt' => '2027-06-12T18:00:00',
                'timezone' => 'America/New_York',
            ],
            'guest' => ['name' => 'Rosa Fernández'],
            'stage' => $saveTheDate ? 'save_the_date' : 'invitation',
            'locale' => $locale,
            'theme' => $this->invitation->themePayload($theme, [], $package['package'] ?? null),
            'sections' => $resolved,
            'branding' => ['watermark' => true],
        ];
    }

    /** The sections of the Save the date, as the real invitation draws them (no photo, no program). @return list<array<string, mixed>> */
    private function saveTheDateSections(): array
    {
        $plain = fn (int $order, string $type, array $title, array $text = ['es' => null, 'en' => null]) => [
            'type' => $type, 'enabled' => true, 'order' => $order, 'title' => $title, 'text' => $text, 'settings' => [],
        ];

        return [
            $plain(1, 'cover', ['es' => 'Guarda la fecha', 'en' => 'Save the date']),
            $plain(2, 'countdown', ['es' => 'Faltan', 'en' => 'Counting the days']),
            $plain(3, 'save_the_date', ['es' => null, 'en' => null]),
            $plain(4, 'closing', ['es' => 'Pronto más detalles', 'en' => 'More details soon'], ['es' => 'La invitación completa te llegará por este mismo enlace.', 'en' => 'The full invitation will reach you on this same link.']),
        ];
    }

    /** @return array<string, mixed> */
    private function data(array $section, array $sample): array
    {
        return match ($section['type']) {
            'countdown' => ['startsAt' => '2027-06-12T18:00:00', 'timezone' => 'America/New_York'],
            'save_the_date' => ['message' => 'Reserva esta fecha, ¡queremos celebrar contigo!', 'calendar' => ['google' => 'https://calendar.google.com/calendar/render?action=TEMPLATE', 'icsPath' => 'calendar.ics']],
            'cover' => ['eventName' => $sample['name'], 'description' => $sample['description'], 'image' => $section['settings']['image'] ?? null],
            'itinerary' => ['items' => $sample['itinerary']],
            'locations' => ['items' => $sample['locations']],
            'dress_code' => $sample['dressCode'],
            'padrinos' => ['items' => $sample['padrinos']],
            'gallery' => ['photos' => $sample['photos']],
            // No real song in a preview: the player is drawn disabled.
            'music' => ['url' => '', 'title' => 'Nuestra canción', 'mimeType' => 'audio/mpeg', 'loop' => true],
            'rsvp' => [
                'guest' => ['id' => 1, 'name' => 'Rosa Fernández', 'status' => 'pending'],
                'companions' => [['id' => 2, 'name' => 'Luis Fernández', 'status' => 'pending']],
                'deadline' => '2027-05-20T23:59:00',
                'isOpen' => true,
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function sample(bool $wedding): array
    {
        $links = fn (string $q) => [
            'google' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q),
            'apple' => 'https://maps.apple.com/?' . http_build_query(['q' => $q]),
            'waze' => 'https://waze.com/ul?' . http_build_query(['q' => $q, 'navigate' => 'yes']),
        ];

        $place = fn (string $type, string $name, string $address, string $start) => [
            'type' => $type, 'name' => $name, 'address' => $address, 'city' => 'Miami', 'state' => 'FL', 'zipCode' => '33101',
            'country' => 'US', 'directions' => null, 'latitude' => null, 'longitude' => null,
            'startTime' => $start, 'endTime' => null, 'images' => [], 'links' => $links("{$name}, {$address}, Miami"),
        ];

        return [
            'name' => $wedding ? 'Boda de Camila y Andrés' : 'Los XV de Valentina',
            'description' => $wedding ? 'Con la bendición de nuestras familias.' : 'Una noche para recordar.',
            'itinerary' => [
                ['type' => 'ceremony', 'title' => $wedding ? 'Ceremonia' : 'Misa', 'description' => null, 'startTime' => '2027-06-12 16:00:00', 'endTime' => null],
                ['type' => 'reception', 'title' => 'Recepción', 'description' => null, 'startTime' => '2027-06-12 18:00:00', 'endTime' => null],
                ['type' => 'dinner', 'title' => 'Cena', 'description' => null, 'startTime' => '2027-06-12 19:30:00', 'endTime' => null],
            ],
            'locations' => [
                $place('ceremony', 'Iglesia San Juan', '100 Ocean Dr', '2027-06-12 16:00:00'),
                $place('reception', 'Salón Las Palmas', '200 Biscayne Blvd', '2027-06-12 18:00:00'),
            ],
            'dressCode' => [
                'type' => 'formal', 'description' => 'Formal, en tonos suaves.', 'reservedColors' => ['#ffffff'],
                'suggestedColors' => ['#b04a62', '#5f7a5b'], 'images' => [],
            ],
            // Plain colored tiles (inline SVG): the preview has no real photos.
            'photos' => array_map(fn (int $i, string $color) => [
                'id' => $i, 'title' => "Foto {$i}", 'description' => null, 'year' => 2015 + $i,
                'url' => 'data:image/svg+xml,' . rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' width='600' height='450'><rect width='600' height='450' fill='{$color}'/></svg>"),
                'thumbUrl' => 'data:image/svg+xml,' . rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' width='150' height='150'><rect width='150' height='150' fill='{$color}'/></svg>"),
            ], [1, 2, 3, 4], ['#e8c9d0', '#cfdccb', '#c9d5e6', '#efe2bd']),
            'padrinos' => [
                ['name' => 'Tía Elena', 'relationship' => 'Madrina', 'sponsors' => ['Pastel'], 'thanks' => 'Gracias por tanto cariño.'],
                ['name' => 'Tío Marcos', 'relationship' => 'Padrino', 'sponsors' => ['Música'], 'thanks' => null],
            ],
        ];
    }
}
