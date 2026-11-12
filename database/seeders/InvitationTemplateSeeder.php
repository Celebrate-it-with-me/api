<?php

namespace Database\Seeders;

use App\Models\EventType;
use App\Models\InvitationTemplate;
use Illuminate\Database\Seeder;

/**
 * Reference data: the free "Clásica" invitation template, one row per event type
 * so the default copy speaks about the quinceañera or the wedding. Idempotent.
 */
class InvitationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $quince = EventType::query()->where('slug', 'quinceanera')->value('id');
        $wedding = EventType::query()->where('slug', 'wedding')->value('id');

        $this->upsert('classic-quinceanera', 'Clásica · Quinceañera', $quince, [
            'cover' => [
                'title' => ['es' => 'Te invito a celebrar', 'en' => 'Join me to celebrate'],
                'text' => ['es' => 'Quiero compartir este día tan especial contigo.', 'en' => 'I want to share this special day with you.'],
            ],
            'locations' => [
                'title' => ['es' => 'Dónde celebramos', 'en' => 'Where we celebrate'],
                'text' => ['es' => null, 'en' => null],
            ],
            'dress_code' => [
                'title' => ['es' => 'Código de vestimenta', 'en' => 'Dress code'],
                'text' => ['es' => null, 'en' => null],
            ],
            'rsvp' => [
                'title' => ['es' => '¿Me acompañas?', 'en' => 'Will you join me?'],
                'text' => ['es' => 'Confirma tu asistencia para que todo salga perfecto.', 'en' => 'Let me know if you can make it so everything is perfect.'],
            ],
            'closing' => [
                'title' => ['es' => 'Gracias por ser parte de mi día', 'en' => 'Thank you for being part of my day'],
                'text' => ['es' => 'Te espero con mucho cariño.', 'en' => 'I can’t wait to see you.'],
            ],
        ]);

        $this->upsert('classic-wedding', 'Clásica · Boda', $wedding, [
            'cover' => [
                'title' => ['es' => 'Te invitamos a celebrar', 'en' => 'Join us to celebrate'],
                'text' => ['es' => 'Queremos compartir este día tan especial contigo.', 'en' => 'We want to share this special day with you.'],
            ],
            'locations' => [
                'title' => ['es' => 'Dónde celebramos', 'en' => 'Where we celebrate'],
                'text' => ['es' => null, 'en' => null],
            ],
            'dress_code' => [
                'title' => ['es' => 'Código de vestimenta', 'en' => 'Dress code'],
                'text' => ['es' => null, 'en' => null],
            ],
            'rsvp' => [
                'title' => ['es' => '¿Nos acompañas?', 'en' => 'Will you join us?'],
                'text' => ['es' => 'Confirma tu asistencia para que todo salga perfecto.', 'en' => 'Let us know if you can make it so everything is perfect.'],
            ],
            'closing' => [
                'title' => ['es' => 'Gracias por acompañarnos', 'en' => 'Thank you for celebrating with us'],
                'text' => ['es' => 'Los esperamos con mucho cariño.', 'en' => 'We can’t wait to see you.'],
            ],
        ]);
    }

    /**
     * @param  array<string, array{title: array<string, ?string>, text: array<string, ?string>}>  $copy
     */
    private function upsert(string $slug, string $name, ?int $eventTypeId, array $copy): void
    {
        $order = 0;
        $sections = [];

        foreach ($copy as $type => $texts) {
            $sections[] = [
                'type' => $type,
                'enabled' => true,
                'order' => ++$order,
                'title' => $texts['title'],
                'text' => $texts['text'],
                'settings' => $type === 'cover' ? ['image' => null] : (object) [],
            ];
        }

        InvitationTemplate::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'event_type_id' => $eventTypeId,
                'tier' => InvitationTemplate::TIER_FREE,
                'default_sections' => $sections,
                'theme_defaults' => self::classicTheme(),
                'is_active' => true,
            ],
        );
    }

    /**
     * Classic theme: colors map to the CSS variables the public renderer applies.
     * The three presets are the free-plan choices; free-form colors are premium.
     */
    public static function classicTheme(): array
    {
        $base = ['surface' => '#fbf7f2', 'surfaceAlt' => '#f4ede4', 'ink' => '#2b2420', 'inkSoft' => '#5b524c', 'accent' => '#c9a24e'];

        return [
            'theme' => 'classic',
            'fonts' => ['heading' => 'Fraunces', 'body' => 'Figtree'],
            'preset' => 'rose',
            'presets' => [
                'rose' => ['label' => ['es' => 'Rosa', 'en' => 'Rose'], 'colors' => $base + ['primary' => '#b04a62']],
                'sage' => ['label' => ['es' => 'Salvia', 'en' => 'Sage'], 'colors' => $base + ['primary' => '#5f7a5b']],
                'navy' => ['label' => ['es' => 'Azul noche', 'en' => 'Midnight'], 'colors' => $base + ['primary' => '#2f4a6d']],
            ],
        ];
    }
}
