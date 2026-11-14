<?php

namespace App\Http\Services\WhatsApp;

use RuntimeException;

/** A failure talking to WhatsApp. `retryable` is true for rate limits, 5xx and network errors; false for rejections (bad number, expired token...). */
class WhatsAppException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
