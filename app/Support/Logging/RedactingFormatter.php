<?php

namespace App\Support\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/** Wraps a handler's formatter and masks secrets in query strings in whatever it writes, stack traces and exception messages included. */
final class RedactingFormatter implements FormatterInterface
{
    public function __construct(private readonly FormatterInterface $inner) {}

    public function format(LogRecord $record): mixed
    {
        return $this->redact($this->inner->format($record));
    }

    public function formatBatch(array $records): mixed
    {
        return $this->redact($this->inner->formatBatch($records));
    }

    private function redact(mixed $formatted): mixed
    {
        return is_string($formatted) ? RedactSensitiveQuery::in($formatted) : $formatted;
    }
}
