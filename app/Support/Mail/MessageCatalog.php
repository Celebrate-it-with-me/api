<?php

namespace App\Support\Mail;

/**
 * Which registry mails have editable copy, where that copy lives in lang/{es,en}/mail.php and which fields of it an admin
 * may change (docs/plans/message-editor-contract.md). The editable fields are derived from the Spanish file: top-level
 * strings of the mail's group that exist in both languages and are not plural forms. Strings shared by several mails
 * (the admin security title, signature...) are not editable per mail.
 */
class MessageCatalog
{
    /** Registry key => path inside the `mail` lang group. Mails without lang copy (support-request) are not listed. */
    public const PATHS = [
        'confirm-account' => 'confirm',
        'welcome' => 'welcome',
        'invoice' => 'invoice',
        'credit-note' => 'credit_note',
        'reset-password' => 'reset',
        'invite-registered' => 'invite_registered',
        'invite-unregistered' => 'invite_unregistered',
        'ownership-new' => 'ownership_new',
        'ownership-previous' => 'ownership_previous',
        'impersonation-ended' => 'impersonation_ended',
        'admin-email-confirm' => 'admin_email_confirm',
        'admin-invitation' => 'admin_invitation',
        'admin-security-password-changed' => 'admin_security.types.password_changed',
        'admin-security-email-changed' => 'admin_security.types.email_changed',
        'admin-security-two-factor-enabled' => 'admin_security.types.two_factor_enabled',
        'admin-security-two-factor-disabled' => 'admin_security.types.two_factor_disabled',
        'admin-security-recovery-codes-regenerated' => 'admin_security.types.recovery_codes_regenerated',
        'admin-security-two-factor-reset' => 'admin_security.types.two_factor_reset_by_admin',
        'admin-security-new-device' => 'admin_security.types.new_device',
        'budget-reminder' => 'budget_reminder',
        'account-deleted' => 'account_deleted',
        'test' => 'test',
    ];

    public const MAX_LENGTH = ['subject' => 150, 'preheader' => 200, 'title' => 150];

    public const DEFAULT_MAX_LENGTH = 1000;

    /** Sample value for a placeholder, for the editor's chips. */
    private const EXAMPLES = [
        'name' => 'Valentina', 'event' => 'Los XV de Valentina', 'number' => 'QV-2026-000001', 'invoice' => 'QV-2026-000001',
        'total' => 'US$79.00', 'amount' => 'US$79.00', 'role' => 'editor', 'inviter' => 'Valentina Ríos', 'minutes' => '12',
        'hours' => '72', 'days' => '3', 'count' => '2', 'date' => '13/11/2026 10:42', 'unit' => 'minutos', 'why' => 'para ayudarte con tu cuenta',
        'email' => 'hola@quinvite.com', 'actor' => 'Marisol Reyes', 'device' => 'Chrome en Windows', 'when' => '13/11/2026 10:42', 'ip' => '190.12.34.x',
    ];

    /** @var array<string, array> */
    private static array $files = [];

    /** @return list<string> editable registry keys, in registry order */
    public static function keys(): array
    {
        return array_values(array_filter(array_keys(MailRegistry::all()), fn (string $key) => self::isEditable($key)));
    }

    public static function isEditable(string $key): bool
    {
        $definition = MailRegistry::find($key);

        return isset(self::PATHS[$key]) && $definition && $definition->hasMail();
    }

    public static function definition(string $key): ?MailDefinition
    {
        return self::isEditable($key) ? MailRegistry::find($key) : null;
    }

    public static function path(string $key): ?string
    {
        return self::PATHS[$key] ?? null;
    }

    /** @return list<string> */
    public static function fields(string $key): array
    {
        $es = self::group($key, 'es');
        $en = self::group($key, 'en');
        $fields = [];
        foreach ($es as $field => $text) {
            if (is_string($text) && is_string($en[$field] ?? null) && ! self::isPlural($text) && ! self::isPlural($en[$field])) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    public static function default(string $key, string $locale, string $field): string
    {
        return (string) (self::group($key, $locale)[$field] ?? '');
    }

    public static function maxLength(string $field): int
    {
        return self::MAX_LENGTH[$field] ?? self::DEFAULT_MAX_LENGTH;
    }

    /** @return list<string> the `:placeholder` names of a text, in order of appearance */
    public static function variables(string $text): array
    {
        preg_match_all('/(?<![A-Za-z0-9_]):([A-Za-z_]\w*)/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    public static function example(string $variable): string
    {
        return self::EXAMPLES[$variable] ?? $variable;
    }

    /** Lang item (inside the `mail` group) of one field, e.g. `confirm.subject`. */
    public static function item(string $key, string $field): string
    {
        return self::PATHS[$key] . '.' . $field;
    }

    /** @return array<string, mixed> */
    private static function group(string $key, string $locale): array
    {
        self::$files[$locale] ??= (static function () use ($locale) {
            $file = lang_path("{$locale}/mail.php");

            return is_file($file) ? (array) include $file : [];
        })();

        $node = self::$files[$locale];
        foreach (explode('.', self::PATHS[$key] ?? '') as $segment) {
            $node = is_array($node) ? ($node[$segment] ?? []) : [];
        }

        return is_array($node) ? $node : [];
    }

    private static function isPlural(string $text): bool
    {
        return (bool) preg_match('/^\s*[\{\[]|\|\s*[\{\[]/', $text);
    }
}
