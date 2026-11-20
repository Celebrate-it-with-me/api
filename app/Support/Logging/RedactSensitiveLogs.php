<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/**
 * Log channel `tap` (config/logging.php): no log line carries the token, signature, hash, code or password of a URL query string.
 * Two layers: a processor for the message and string context, and a formatter wrapper for everything the handler writes
 * (exception messages and traces are only rendered there). The hosting provider's own access log is outside the app.
 */
class RedactSensitiveLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        $monolog->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            message: RedactSensitiveQuery::in($record->message),
            context: self::context($record->context),
        ));

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface && ! $handler->getFormatter() instanceof RedactingFormatter) {
                $handler->setFormatter(new RedactingFormatter($handler->getFormatter()));
            }
        }
    }

    /** @param array<mixed> $context @return array<mixed> */
    private static function context(array $context): array
    {
        return array_map(fn ($value) => match (true) {
            is_string($value) => RedactSensitiveQuery::in($value),
            is_array($value) => self::context($value),
            default => $value,
        }, $context);
    }
}
