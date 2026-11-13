<?php

namespace App\Support\Mail;

use App\Mail\ConfirmAccountEmail;
use App\Mail\ImpersonationEndedMail;
use App\Mail\InvoiceMail;
use App\Mail\InviteRegisteredUserMail;
use App\Mail\InviteUnregisteredUserMail;
use App\Mail\OwnershipTransferredMail;
use App\Mail\ResetPasswordEmail;
use App\Mail\SupportRequestMail;
use App\Mail\TestEmail;
use App\Mail\WelcomeEmail;
use App\Models\BudgetItem;
use App\Models\EventCollaborationInvite;
use App\Models\Events;
use App\Models\ImpersonationSession;
use App\Models\Invoice;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\UserPreference;
use App\Notifications\BudgetDatabaseNotification;
use App\Notifications\BudgetItemReminderNotification;
use App\Notifications\CommentCreatedNotification;
use App\Notifications\CommentDatabaseNotification;
use App\Notifications\DatabaseNotification;
use App\Notifications\EventDatabaseNotification;
use App\Notifications\LocationDatabaseNotification;
use App\Notifications\MusicDatabaseNotification;
use App\Notifications\RsvpDatabaseNotification;
use App\Notifications\SaveTheDateDatabaseNotification;
use App\Notifications\SuggestedMusicNotification;

/**
 * Every Mailable and Notification of the system, with sample data (unsaved factories, no database writes) and the
 * languages it speaks. It feeds the /dev/mail gallery, `php artisan mail:preview-all` and MailRegistryTest, which
 * fails when a class in app/ is missing here.
 *
 * To add a mail: create the class and its markdown view (resources/views/mail), add its copy to lang/es/mail.php and
 * lang/en/mail.php, then add one `new MailDefinition(...)` below with a sample closure.
 */
class MailRegistry
{
    /** @return array<string, MailDefinition> keyed by MailDefinition::$key */
    public static function all(): array
    {
        $both = MailLocale::SUPPORTED;
        $definitions = [
            new MailDefinition('confirm-account', 'Confirmar correo', ConfirmAccountEmail::class, $both, ['mail'],
                fn (string $lang) => new ConfirmAccountEmail(self::user($lang), MailLinks::app('confirm-email?confirm=sample-signed-token')),
                'Al registrarse, o cuando el equipo crea el evento de una dueña.'),
            new MailDefinition('welcome', 'Bienvenida', WelcomeEmail::class, $both, ['mail'],
                fn (string $lang) => new WelcomeEmail(self::user($lang)),
                'Cuando el equipo crea el evento de una dueña ya confirmada.'),
            new MailDefinition('invoice', 'Factura de la compra', InvoiceMail::class, $both, ['mail'],
                fn (string $lang) => new InvoiceMail(self::invoice($lang)),
                'Al confirmarse un pago (también el manual) y al reenviarla desde el admin; lleva el PDF adjunto.'),
            new MailDefinition('reset-password', 'Restablecer contraseña', ResetPasswordEmail::class, $both, ['mail'],
                fn (string $lang) => new ResetPasswordEmail(self::user($lang), MailLinks::app('check-password-link?confirm=sample-signed-token')),
                'Al pedir "Olvidé mi contraseña" (o desde el admin).'),
            new MailDefinition('invite-registered', 'Invitación a colaborar (con cuenta)', InviteRegisteredUserMail::class, $both, ['mail'],
                fn (string $lang) => (new InviteRegisteredUserMail(self::invite()))->locale($lang),
                'Invitación de colaboración a alguien que ya tiene cuenta.'),
            new MailDefinition('invite-unregistered', 'Invitación a colaborar (sin cuenta)', InviteUnregisteredUserMail::class, $both, ['mail'],
                fn (string $lang) => (new InviteUnregisteredUserMail(self::invite()))->locale($lang),
                'Invitación de colaboración a alguien sin cuenta; el enlace vence en 7 días.'),
            new MailDefinition('ownership-new', 'Titularidad: nueva dueña', OwnershipTransferredMail::class, $both, ['mail'],
                fn (string $lang) => new OwnershipTransferredMail(self::event(), self::user($lang), 'new'),
                'El equipo traspasa el evento: aviso a quien lo recibe.'),
            new MailDefinition('ownership-previous', 'Titularidad: dueña anterior', OwnershipTransferredMail::class, $both, ['mail'],
                fn (string $lang) => new OwnershipTransferredMail(self::event(), self::user($lang), 'previous'),
                'El equipo traspasa el evento: aviso a quien lo tenía.'),
            new MailDefinition('impersonation-ended', 'Fin de la sesión de ayuda', ImpersonationEndedMail::class, $both, ['mail'],
                fn (string $lang) => new ImpersonationEndedMail(self::session(), self::user($lang)),
                'Al terminar una sesión de ayuda del equipo en su cuenta.'),
            new MailDefinition('budget-reminder', 'Recordatorio de pagos', BudgetItemReminderNotification::class, $both, ['database', 'mail'],
                fn (string $lang) => [new BudgetItemReminderNotification(self::budgetItems(), self::event()), self::user($lang)],
                'Comando diario budget-item:reminders (7, 3, 1 y 0 días antes).'),
            new MailDefinition('support-request', 'Solicitud de ayuda (interno)', SupportRequestMail::class, ['es'], ['mail'],
                fn (string $lang) => new SupportRequestMail(self::supportRequest()),
                'Al buzón de soporte cuando alguien pulsa "Necesito ayuda". Texto plano, en español: lo lee el equipo.'),
            new MailDefinition('test', 'Correo de prueba', TestEmail::class, $both, ['mail'],
                fn (string $lang) => new TestEmail($lang),
                'Para comprobar que el SMTP funciona.'),
        ];

        // Notifications that only write to the bell (database channel): no mail to preview, but they must be listed.
        foreach ([
            BudgetDatabaseNotification::class, CommentCreatedNotification::class, CommentDatabaseNotification::class,
            DatabaseNotification::class, EventDatabaseNotification::class, LocationDatabaseNotification::class,
            MusicDatabaseNotification::class, RsvpDatabaseNotification::class, SaveTheDateDatabaseNotification::class,
            SuggestedMusicNotification::class,
        ] as $class) {
            $definitions[] = new MailDefinition(
                'db-' . str(class_basename($class))->beforeLast('Notification')->kebab(),
                class_basename($class), $class, [], ['database'], null, 'Solo campana de la app (canal database), sin correo.',
            );
        }

        return collect($definitions)->keyBy('key')->all();
    }

    public static function find(string $key): ?MailDefinition
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function classes(): array
    {
        return array_values(array_unique(array_map(fn (MailDefinition $d) => $d->class, self::all())));
    }

    private static function user(string $lang): User
    {
        $user = User::factory()->make(['name' => 'Valentina Ríos', 'email' => 'valentina@example.com']);
        $user->id = 1;
        $user->setRelation('preferences', new UserPreference(['language' => $lang, 'timezone' => 'America/New_York']));

        return $user;
    }

    private static function invoice(string $lang): Invoice
    {
        return new Invoice([
            'number' => 'QV-2026-000001', 'series' => 'QV', 'year' => 2026, 'sequence' => 1, 'status' => 'issued', 'locale' => $lang,
            'issuer' => config('invoicing.issuer'),
            'recipient' => ['name' => 'Valentina Ríos', 'email' => 'valentina@example.com'],
            'lines' => [['description_key' => 'full_event', 'event_name' => 'Los XV de Valentina', 'quantity' => 1, 'unit_cents' => 9900, 'total_cents' => 9900]],
            'subtotal_cents' => 9900, 'discount_cents' => 2000, 'tax_rate' => 0, 'tax_cents' => 0, 'total_cents' => 7900, 'currency' => 'USD',
            'payment_method' => 'stripe', 'payment_reference' => 'pi_sample', 'issued_at' => now(),
        ]);
    }

    private static function event(): Events
    {
        // Explicit foreign keys so the factory does not create an event type and a plan in the database.
        $event = Events::factory()->make(['event_name' => 'Los XV de Valentina', 'event_type_id' => 1, 'event_plan_id' => 1]);
        $event->id = 14;

        return $event;
    }

    private static function invite(): EventCollaborationInvite
    {
        $invite = new EventCollaborationInvite([
            'event_id' => 14, 'email' => 'carlos@example.com', 'role' => 'editor', 'token' => 'sample-invite-token',
        ]);
        $invite->setRelation('event', self::event());

        return $invite;
    }

    private static function session(): ImpersonationSession
    {
        return (new ImpersonationSession)->forceFill([
            'started_at' => now()->subMinutes(12), 'ended_at' => now(), 'support_request_id' => 41,
        ]);
    }

    private static function supportRequest(): SupportRequest
    {
        $request = (new SupportRequest)->forceFill([
            'id' => 41, 'message' => 'No me deja subir la foto de portada de la invitación.',
            'context' => ['route' => '/dashboard/events/14/invitation', 'browser' => 'Safari 18, iPhone'],
        ]);
        $request->setRelation('user', self::user('es'));
        $request->setRelation('event', self::event());

        return $request;
    }

    /** @return list<array{item: BudgetItem, threshold: int}> */
    private static function budgetItems(): array
    {
        $item = fn (string $title, int $days, float $cost) => (new BudgetItem)->forceFill([
            'title' => $title, 'due_date' => now()->addDays($days)->startOfDay(), 'estimated_cost' => $cost,
        ]);

        return [
            ['item' => $item('Salón y banquete', 7, 4800), 'threshold' => 7],
            ['item' => $item('Fotógrafo y video', 1, 950.5), 'threshold' => 1],
            ['item' => $item('Vestido de la quinceañera', 0, 1200), 'threshold' => 0],
        ];
    }
}
