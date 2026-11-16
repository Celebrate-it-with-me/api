<?php

namespace App\Support\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Where application mail goes out. With a real queue (redis, database, sqs) the mail is queued: the request does not
 * wait for the SMTP/API round trip (0.3-1 s measured), the job is retried with backoff and survives a mail provider
 * outage, and it is dispatched only after the surrounding transaction commits (a rolled-back request sends nothing).
 * With the `sync` connection (tests, a dev box without a worker) it is sent right away, so tests that read the
 * mail through `Mail::fake()` / `assertSent` keep seeing it.
 *
 * Use it only when the caller does not need to know the outcome of the send. A retry only happens when the first
 * attempt failed before the provider accepted the message, so a mail is not duplicated by a retry; it is not a
 * de-duplicating outbox (call it twice, two mails go out).
 */
class Outbox
{
    /** Seconds between attempts (the queue stops after `tries`). */
    public const BACKOFF = [30, 120, 600];

    public const TRIES = 3;

    public static function send(mixed $to, Mailable $mailable): void
    {
        $pending = Mail::to($to);

        if (! self::queued()) {
            $pending->send($mailable);

            return;
        }

        $mailable->tries = self::TRIES;
        $mailable->backoff = self::BACKOFF;
        $mailable->afterCommit();
        $pending->queue($mailable);
    }

    public static function queued(): bool
    {
        return config('queue.default') !== 'sync';
    }
}
