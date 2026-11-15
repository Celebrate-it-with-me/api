<?php

namespace App\Support\Mail;

use App\Models\User;

/** Which language a transactional mail speaks: es or en, from the recipient's preferences (Spanish by default). */
class MailLocale
{
    public const SUPPORTED = ['es', 'en'];

    public const DEFAULT = 'es';

    public static function forUser(?User $user): string
    {
        $language = strtolower((string) $user?->preferences?->language);

        return str_starts_with($language, 'en') ? 'en' : (str_starts_with($language, 'es') ? 'es' : self::DEFAULT);
    }

    /** First word of a full name, for greetings ("Hola, Valentina"). */
    public static function firstName(?string $name): string
    {
        return trim(explode(' ', trim((string) $name))[0]);
    }

    /** Escapes Markdown so user-provided text (event and person names) cannot add links or formatting to a mail. */
    public static function md(?string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()<>#+!|~])/', '\\\\$1', (string) $text);
    }

    /**
     * An event name typed by someone else, used in the subject and text of a mail sent from our domain (audit W-03):
     * no links, control characters or line breaks, at most 60 characters.
     */
    public static function eventName(?string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\p{Cc}\p{Cf}]+/u', ' ', (string) $name) ?? '';
        $name = preg_replace('~(?:[a-z][a-z0-9+.-]*://|www\.)\S*~iu', ' ', $name) ?? '';
        $name = preg_replace('~\S+@\S+~u', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return mb_strlen($name) > 60 ? rtrim(mb_substr($name, 0, 60)) : $name;
    }

    /** Role of an event collaborator as a word in the mail language. */
    public static function role(?string $role, string $lang): string
    {
        $key = "mail.roles.{$role}";
        $text = trans($key, [], $lang);

        return $text === $key ? (string) $role : $text;
    }
}
