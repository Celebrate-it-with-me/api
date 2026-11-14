<?php

namespace App\Support\WhatsApp;

/**
 * The five WhatsApp templates (docs/plans/communications-contract.md): keys, locales, fixed variables, buttons and the
 * starting draft text. The text is a DRAFT for the owner to review before it goes to Meta; the admin edits it afterwards.
 *
 * Bodies use Meta's positional markers `{{1}}`..`{{n}}`, in the order of VARIABLES (contract clarification 2026-11-14).
 * BODIES is written with names for readability and converted by rows(). A marker never starts or ends the body.
 */
final class TemplateCatalog
{
    public const KEYS = ['invitation', 'save_the_date', 'reminder_pending', 'rsvp_confirmation', 'padrino_reminder'];

    public const LOCALES = ['es', 'en'];

    /** Templates the organizer can send in bulk (padrino_reminder waits for the Padrinos module). */
    public const SENDABLE_KEYS = ['invitation', 'save_the_date', 'reminder_pending'];

    public const VARIABLES = [
        'invitation' => ['guest_name', 'event_name', 'event_date', 'link'],
        'save_the_date' => ['guest_name', 'event_name', 'event_date', 'link'],
        'reminder_pending' => ['guest_name', 'event_name', 'days_left', 'link'],
        'rsvp_confirmation' => ['guest_name', 'event_name', 'status_text'],
        'padrino_reminder' => ['padrino_name', 'event_name', 'item_name', 'amount', 'due_date'],
    ];

    public const CATEGORIES = [
        'invitation' => 'marketing',
        'save_the_date' => 'marketing',
        'reminder_pending' => 'utility',
        'rsvp_confirmation' => 'utility',
        'padrino_reminder' => 'utility',
    ];

    /** Quick-reply buttons (templates that ask for an RSVP). */
    public const BUTTONS = [
        'es' => [['type' => 'quick_reply', 'id' => 'yes', 'text' => 'Sí, asistiré'], ['type' => 'quick_reply', 'id' => 'no', 'text' => 'No podré asistir']],
        'en' => [['type' => 'quick_reply', 'id' => 'yes', 'text' => "Yes, I'll be there"], ['type' => 'quick_reply', 'id' => 'no', 'text' => "Can't make it"]],
    ];

    public const WITH_BUTTONS = ['invitation', 'reminder_pending'];

    public const BODIES = [
        'invitation' => [
            'es' => "¡Hola {{guest_name}}! Con muchísima ilusión te invitamos a {{event_name}}, que celebraremos el {{event_date}}.\n\nAquí tienes tu invitación personal, con todos los detalles y para confirmar tu asistencia: {{link}}\n\nEsperamos compartir contigo este momento tan especial.",
            'en' => "Hi {{guest_name}}! We are so excited to invite you to {{event_name}}, happening on {{event_date}}.\n\nHere is your personal invitation with all the details, and where you can RSVP: {{link}}\n\nWe can't wait to celebrate this special moment with you.",
        ],
        'save_the_date' => [
            'es' => "¡Hola {{guest_name}}! Guarda este día en tu calendario: {{event_name}} será el {{event_date}}.\n\nMuy pronto te enviaremos la invitación completa. Mientras tanto, mira la fecha aquí: {{link}}\n\n¡Queremos que estés con nosotros!",
            'en' => "Hi {{guest_name}}! Please save the date: {{event_name}} will be on {{event_date}}.\n\nYour full invitation is coming soon. In the meantime, see the date here: {{link}}\n\nWe would love to have you with us!",
        ],
        'reminder_pending' => [
            'es' => "Hola {{guest_name}}, ¡ya casi llega {{event_name}}! Faltan solo {{days_left}} y aún no tenemos tu respuesta. ¿Nos acompañas?\n\nPuedes responder aquí abajo o abrir tu invitación: {{link}}\n\nGracias por ayudarnos a prepararlo todo.",
            'en' => "Hi {{guest_name}}, {{event_name}} is almost here! Only {{days_left}} to go and we still haven't heard from you. Will you join us?\n\nReply below or open your invitation: {{link}}\n\nThank you for helping us get everything ready.",
        ],
        'rsvp_confirmation' => [
            'es' => 'Hola {{guest_name}}, recibimos tu respuesta para {{event_name}}: {{status_text}}. ¡Gracias por avisarnos!',
            'en' => 'Hi {{guest_name}}, we got your reply for {{event_name}}: {{status_text}}. Thank you for letting us know!',
        ],
        'padrino_reminder' => [
            'es' => 'Hola {{padrino_name}}, te escribimos de parte de {{event_name}}. Te recordamos con cariño tu compromiso con {{item_name}} por {{amount}}, con fecha del {{due_date}}. ¡Gracias por ser parte de este día tan especial!',
            'en' => 'Hi {{padrino_name}}, we are writing on behalf of {{event_name}}. A gentle reminder of your commitment to {{item_name}} for {{amount}}, due on {{due_date}}. Thank you for being part of this special day!',
        ],
    ];

    public const SAMPLES = [
        'guest_name' => 'Ana', 'event_name' => 'Los XV de Valentina', 'event_date' => 'sábado 14 de marzo de 2027', 'link' => 'https://quinvite.com/i/xv-de-valentina/ABC123',
        'days_left' => '7 días', 'status_text' => 'confirmaste tu asistencia', 'padrino_name' => 'Tío Raúl', 'item_name' => 'el pastel', 'amount' => '$400', 'due_date' => '1 de febrero de 2027',
    ];

    public static function metaName(string $key, string $locale): string
    {
        return "quinvite_{$key}_{$locale}";
    }

    /** Markers found in a body, in order of first appearance: `['1', '2']` for `{{1}} ... {{2}}`. Anything else (names) is kept as is. @return list<string> */
    public static function placeholders(string $body): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /** The markers a body must use for a template with this many variables. @return list<string> */
    public static function expectedMarkers(array $variables): array
    {
        return array_map('strval', range(1, max(1, count($variables))));
    }

    /** `{{guest_name}}` to `{{1}}` following the variable order. */
    public static function toPositional(string $body, array $variables): string
    {
        foreach ($variables as $i => $name) {
            $body = preg_replace('/\{\{\s*' . preg_quote($name, '/') . '\s*\}\}/', '{{' . ($i + 1) . '}}', $body);
        }

        return $body;
    }

    /** @return list<array<string, mixed>> the rows to create, one per (key, locale) */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::KEYS as $key) {
            foreach (self::LOCALES as $locale) {
                $rows[] = [
                    'key' => $key, 'locale' => $locale, 'meta_name' => self::metaName($key, $locale),
                    'category' => self::CATEGORIES[$key], 'body' => self::toPositional(self::BODIES[$key][$locale], self::VARIABLES[$key]),
                    'buttons' => json_encode(in_array($key, self::WITH_BUTTONS, true) ? self::BUTTONS[$locale] : []),
                    'variables' => json_encode(self::VARIABLES[$key]), 'status' => 'draft',
                ];
            }
        }

        return $rows;
    }
}
