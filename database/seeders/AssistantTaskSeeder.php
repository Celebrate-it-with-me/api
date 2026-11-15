<?php

namespace Database\Seeders;

use App\Models\AssistantTask;
use Illuminate\Database\Seeder;

/**
 * Starting catalog of the smart assistant (docs/plans/assistant-contract.md). Like ModuleSeeder it only creates what is
 * missing: once a task exists, whatever the admin edited (texts, margins, switch) is never overwritten by a deploy.
 */
class AssistantTaskSeeder extends Seeder
{
    /**
     * key => [event_type, phase, sort_order, start_months, late_months, prerequisites, detector, module_key, route,
     *         title_es, title_en, why_es, why_en].
     * The `why` texts are drafts the owner reviews; they only use the markers {months} {guests} {done} {unlocks}.
     */
    public const CATALOG = [
        'budget_cap' => ['both', 'foundation', 10, 14, 10, [], 'budget_cap', 'budget', '/dashboard/budget',
            'Define cuánto quieres gastar', 'Set your budget',
            'Con un tope claro, cada decisión siguiente es más fácil.', 'A clear cap makes every next decision easier.'],
        'book_venue' => ['both', 'foundation', 20, 12, 8, ['budget_cap'], 'budget_vendor:venue', 'budget', '/dashboard/budget',
            'Reserva el salón', 'Book the venue',
            'Las mejores fechas se agotan primero y de aquí salen {unlocks} cosas más.', 'The best dates go first, and {unlocks} other things depend on it.'],
        'add_locations' => ['both', 'foundation', 30, 11, 6, ['book_venue'], 'locations', 'locations', '/dashboard/locations',
            'Agrega los lugares al evento', 'Add your venues',
            'Tus invitados necesitan saber dónde y cómo llegar.', 'Guests need to know where to go and how to get there.'],
        'guest_list' => ['both', 'foundation', 40, 11, 6, ['budget_cap'], 'guest_list', 'guests', '/dashboard/guests',
            'Arma tu lista de invitados', 'Build your guest list',
            'De la lista sale casi todo: salón, banquete e invitaciones.', 'Almost everything follows from the list: venue, catering, invitations.'],
        'book_ceremony' => ['both', 'foundation', 50, 10, 6, [], 'budget_vendor:ceremony', 'budget', '/dashboard/budget',
            'Reserva la misa o ceremonia', 'Book the ceremony',
            'Las parroquias y oficiantes tienen agenda propia.', 'Churches and officiants book their own calendars.'],
        'choose_padrinos' => ['both', 'foundation', 60, 10, 5, ['guest_list'], 'padrinos', 'padrinos', '/dashboard/padrinos',
            'Elige a tus padrinos', 'Choose your padrinos',
            'Conviene invitarlos pronto: ellos también organizan su tiempo.', 'Ask early: they plan their time too.'],
        'court_of_honor' => ['quinceanera', 'foundation', 70, 10, 5, [], null, null, '/dashboard/timeline',
            'Define la corte de honor', 'Choose your court',
            'Damas, chambelanes o cortejo necesitan tiempo para prepararse.', 'Your court needs time to get ready.'],
        'book_photo_video' => ['both', 'planning', 10, 9, 5, ['book_venue'], 'budget_vendor:photo', 'budget', '/dashboard/budget',
            'Contrata fotografía y video', 'Book photo and video',
            'Es de lo primero que se agota y no se repite.', 'It sells out early and can\'t be redone.'],
        'book_catering' => ['both', 'planning', 20, 8, 4, ['book_venue'], 'budget_vendor:catering', 'budget', '/dashboard/budget',
            'Elige el banquete', 'Choose your catering',
            'Define el costo por persona y el menú.', 'It sets the cost per guest and the menu.'],
        'book_music' => ['both', 'planning', 30, 8, 4, ['book_venue'], 'budget_vendor:music', 'budget', '/dashboard/budget',
            'Contrata el DJ o la música', 'Book the DJ or band',
            'Marca el ritmo de la fiesta.', 'It sets the pace of the party.'],
        'choose_dress' => ['both', 'planning', 40, 8, 3, ['budget_cap'], 'budget_vendor:attire', 'budget', '/dashboard/budget',
            'Elige el vestido o vestuario', 'Choose the dress or attire',
            'Pruebas y arreglos toman varias semanas.', 'Fittings and alterations take weeks.'],
        'save_the_date' => ['both', 'planning', 50, 8, 5, ['guest_list'], 'save_the_date', 'save_the_date', '/dashboard/save-the-date',
            'Manda el Save the date', 'Send the Save the date',
            'Tus invitados se reservan la fecha con tiempo.', 'Guests hold the date early.'],
        'waltz_choreography' => ['quinceanera', 'planning', 60, 7, 3, ['court_of_honor'], 'waltz', 'timeline', '/dashboard/timeline',
            'Empieza el vals', 'Start the waltz',
            'Ensayar con calma se nota en la pista.', 'Calm rehearsals show on the floor.'],
        'guests_contact' => ['both', 'planning', 70, 6, 3, ['guest_list'], 'guests_contact', 'guests', '/dashboard/guests',
            'Completa los teléfonos', 'Add guest phone numbers',
            'Sin teléfono no puedes enviar por WhatsApp.', 'Without a number you can\'t send by WhatsApp.'],
        'book_decoration' => ['both', 'planning', 80, 6, 3, ['book_venue'], 'budget_vendor:decoration', 'budget', '/dashboard/budget',
            'Planea la decoración', 'Plan the decoration',
            'Va con el salón y la paleta que ya elegiste.', 'It follows your venue and palette.'],
        'book_cake' => ['both', 'planning', 90, 5, 2, ['book_catering'], 'budget_vendor:cake', 'budget', '/dashboard/budget',
            'Encarga el pastel', 'Order the cake',
            'Los diseños a medida piden semanas.', 'Custom designs take weeks.'],
        'book_transport' => ['both', 'planning', 100, 5, 2, [], 'budget_vendor:transport', 'budget', '/dashboard/budget',
            'Reserva el transporte', 'Book transport',
            'Limusina o autos a tiempo, sin sorpresas.', 'Cars on time, no surprises.'],
        'dress_code' => ['both', 'details', 10, 5, 2, [], 'dress_code', 'dress_code', '/dashboard/dress-code',
            'Define el código de vestimenta', 'Set the dress code',
            'Tus invitados saben qué ponerse y respetan los colores que reservas para ti.', 'Guests know what to wear and reserved colors stay yours.'],
        'program' => ['both', 'details', 20, 4, 1, ['book_ceremony'], 'program', 'timeline', '/dashboard/timeline',
            'Arma el programa del día', 'Plan the day\'s schedule',
            'Ordena misa, recepción, vals y brindis.', 'It orders ceremony, reception, waltz and toast.'],
        'invitation_design' => ['both', 'details', 30, 4, 2, [], 'invitation_design', null, '@edit-event',
            'Personaliza tu invitación', 'Design your invitation',
            'Es lo primero que ven tus invitados.', 'It\'s the first thing guests see.'],
        'book_favors' => ['both', 'details', 40, 3, 1, [], 'budget_vendor:favors', 'budget', '/dashboard/budget',
            'Elige los recuerdos para tus invitados', 'Choose party favors',
            'Los pedidos personalizados tardan en llegar.', 'Custom orders take time to arrive.'],
        'rsvp_deadline' => ['both', 'invitations', 10, 3, 1, ['guest_list'], 'rsvp_deadline', 'rsvp', '/dashboard/rsvp',
            'Pon fecha límite de confirmación', 'Set an RSVP deadline',
            'Necesitas el número final a tiempo para el salón.', 'You need the final count in time for the venue.'],
        'publish_invitation' => ['both', 'invitations', 20, 3, 1.5, ['add_locations', 'invitation_design'], 'published', null, '@edit-event',
            'Publica tu invitación', 'Publish your invitation',
            'Publicada, ya puedes compartir el enlace.', 'Once it\'s live, you can share the link.'],
        'send_invitations' => ['both', 'invitations', 30, 2.5, 1.5, ['publish_invitation', 'guests_contact'], 'invitations_sent', 'guests', '/dashboard/guests',
            'Envía las invitaciones', 'Send the invitations',
            'Tus invitados necesitan tiempo para confirmar.', 'Guests need time to reply.'],
        'rsvp_follow_up' => ['both', 'invitations', 40, 2, 0.5, ['send_invitations'], 'rsvp_responses', 'rsvp', '/dashboard/guests?status=pending',
            'Da seguimiento a las confirmaciones', 'Follow up on RSVPs',
            'Hay {guests} invitados sin responder.', '{guests} guests haven\'t replied.'],
        'seating_chart' => ['both', 'final', 10, 1.5, 0.5, ['rsvp_follow_up'], 'seating', 'seating', '/dashboard/seating',
            'Organiza las mesas', 'Plan the seating',
            'Con los confirmados ya puedes acomodar a todos.', 'With confirmed guests you can seat everyone.'],
        'final_headcount' => ['both', 'final', 20, 1, 0.25, ['rsvp_follow_up'], null, null, '/dashboard/guests',
            'Confirma el número final', 'Confirm the final headcount',
            'El salón y el banquete lo piden.', 'The venue and caterer need it.'],
        'settle_payments' => ['both', 'final', 30, 1, 0, ['book_catering'], 'payments_due', 'budget', '/dashboard/budget',
            'Liquida los pagos pendientes', 'Settle pending payments',
            'Hay saldos que vencen pronto.', 'Some balances are due soon.'],
        'rehearsal' => ['both', 'final', 40, 0.5, 0.1, ['program'], null, null, '/dashboard/timeline',
            'Haz el ensayo', 'Hold the rehearsal',
            'Todo fluye mejor si ya lo practicaron.', 'Everything flows better when practiced.'],
        'emergency_kit' => ['both', 'final', 50, 0.25, 0, [], null, null, '/dashboard/timeline',
            'Prepara el kit de emergencia', 'Pack an emergency kit',
            'Hilo, cargadores, banditas: lo que nadie recuerda.', 'Thread, chargers, bandages: what no one remembers.'],
        'thank_padrinos' => ['both', 'after', 10, -0.1, -1, ['choose_padrinos'], 'padrinos_thanks', 'padrinos', '/dashboard/padrinos',
            'Agradece a tus padrinos', 'Thank your padrinos',
            'Un gracias sincero cierra bien su compromiso.', 'A sincere thank-you closes it well.'],
        'thank_guests' => ['both', 'after', 20, -0.1, -1, [], null, null, '/dashboard/guests',
            'Agradece a tus invitados', 'Thank your guests',
            'Un mensaje sencillo se recuerda.', 'A simple message is remembered.'],
        'memories_review' => ['both', 'after', 30, -0.2, -2, [], null, null, '/dashboard/memories',
            'Revisa tus recuerdos', 'Review your memories',
            'Guarda las fotos y los datos de tu evento mientras los tienes frescos.', 'Save photos and notes while they\'re fresh.'],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $key => $row) {
            AssistantTask::query()->firstOrCreate(['key' => $key], self::attributes($row));
        }
    }

    /** @return array<string, mixed> */
    public static function attributes(array $row): array
    {
        [$type, $phase, $order, $start, $late, $prerequisites, $detector, $module, $route, $titleEs, $titleEn, $whyEs, $whyEn] = $row;

        return [
            'event_type' => $type,
            'phase' => $phase,
            'sort_order' => $order,
            'start_months' => $start,
            'late_months' => $late,
            'prerequisites' => $prerequisites,
            'detector' => $detector,
            'module_key' => $module,
            'route' => $route,
            'title_es' => $titleEs,
            'title_en' => $titleEn,
            'why_es' => $whyEs,
            'why_en' => $whyEn,
            'is_active' => true,
        ];
    }
}
