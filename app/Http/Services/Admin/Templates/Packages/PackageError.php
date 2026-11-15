<?php

namespace App\Http\Services\Admin\Templates\Packages;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Errors of the package endpoints in the shape of the contract: 403/404/409/410 `{message, code}` (plus extra data) and
 * 422 `{message, errors: {<field>: [<code>]}}`.
 */
final class PackageError
{
    /** @param array<string, mixed> $extra */
    public static function make(int $status, string $code, string $message, array $extra = []): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code] + $extra, $status));
    }

    public static function notFound(string $code, string $message): HttpResponseException
    {
        return self::make(404, $code, $message);
    }

    /** @param array<string, mixed> $extra */
    public static function conflict(string $code, string $message, array $extra = []): HttpResponseException
    {
        return self::make(409, $code, $message, $extra);
    }

    public static function gone(string $code, string $message): HttpResponseException
    {
        return self::make(410, $code, $message);
    }

    /** A 422 whose `errors.<field>[0]` is the machine code. */
    public static function invalid(string $field, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'errors' => [$field => [$code]]], 422));
    }
}
