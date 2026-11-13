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

    /** Role of an event collaborator as a word in the mail language. */
    public static function role(?string $role, string $lang): string
    {
        $key = "mail.roles.{$role}";
        $text = trans($key, [], $lang);

        return $text === $key ? (string) $role : $text;
    }
}
