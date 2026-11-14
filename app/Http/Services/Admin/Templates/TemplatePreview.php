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
    public function build(array $sections, array $theme, string $locale, string $eventType): array
    {
        $wedding = $eventType === 'wedding';
        $sample = $this->sample($wedding);

        $resolved = collect($sections)
            ->filter(fn (array $s) => $s['enabled'] ?? false)
            ->sortBy(fn (array $s) => $s['order'] ?? 99)
            ->map(fn (array $s) => [
                'type' => $s['type'],
                'title' => $this->invitation->localized($s['title'] ?? null, $locale),
                'text' => $this->invitation->localized($s['text'] ?? null, $locale),
                'data' => $this->data($s, $sample) ?: (object) [],
            ])
            ->values()
            ->all();

        return [
            'event' => [
                'name' => $sample['name'],
                'typeSlug' => $wedding ? 'wedding' : 'quinceanera',
                'startsAt' => '2027-06-12T18:00:00',
                'timezone' => 'America/New_York',
            ],
            'guest' => ['name' => 'Rosa Fernández'],
            'stage' => 'invitation',
            'locale' => $locale,
            'theme' => $this->invitation->themePayload($theme),
            'sections' => $resolved,
            'branding' => ['watermark' => true],
        ];
    }

    /** @return array<string, mixed> */
    private function data(array $section, array $sample): array
    {
        return match ($section['type']) {
            'cover' => ['eventName' => $sample['name'], 'description' => $sample['description'], 'image' => $section['settings']['image'] ?? null],
            'itinerary' => ['items' => $sample['itinerary']],
            'locations' => ['items' => $sample['locations']],
            'dress_code' => $sample['dressCode'],
            'padrinos' => ['items' => $sample['padrinos']],
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
            'padrinos' => [
                ['name' => 'Tía Elena', 'relationship' => 'Madrina', 'sponsors' => ['Pastel'], 'thanks' => 'Gracias por tanto cariño.'],
                ['name' => 'Tío Marcos', 'relationship' => 'Padrino', 'sponsors' => ['Música'], 'thanks' => null],
            ],
        ];
    }
}
