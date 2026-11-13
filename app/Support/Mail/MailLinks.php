<?php

namespace App\Support\Mail;

/** Absolute links for mail bodies: a mail client has no base URL to resolve relative ones. */
class MailLinks
{
    public static function app(string $path = ''): string
    {
        $base = rtrim((string) (config('app.frontend_app.url') ?: config('app.url')), '/');

        return $base . '/' . ltrim($path, '/');
    }

    public static function support(): string
    {
        return 'mailto:' . config('services.support.email');
    }
}
