<?php

namespace App\Exceptions;

use RuntimeException;

/** A seating rule that was not met. `errorCode` is the stable value the app reads; the message is localized. */
class SeatingException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public static function make(string $code, array $replace = [], array $context = []): self
    {
        return new self($code, (string) __("seating.$code", $replace), $context);
    }
}
