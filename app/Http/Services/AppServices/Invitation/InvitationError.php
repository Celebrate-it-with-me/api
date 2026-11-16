<?php

namespace App\Http\Services\AppServices\Invitation;

use Illuminate\Http\Exceptions\HttpResponseException;

/** An answer `{message, code}` of the "Tu invitación" endpoints; the app shows its own text from the `code`. */
final class InvitationError extends HttpResponseException
{
    /** @param array<string, mixed> $extra */
    public static function make(int $status, string $code, string $message, array $extra = []): self
    {
        return new self(response()->json(['message' => $message, 'code' => $code] + $extra, $status));
    }

    public static function notFound(string $code, string $message): self
    {
        return self::make(404, $code, $message);
    }

    public static function forbidden(string $code, string $message): self
    {
        return self::make(403, $code, $message);
    }

    public static function conflict(string $code, string $message, array $extra = []): self
    {
        return self::make(409, $code, $message, $extra);
    }
}
