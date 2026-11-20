<?php

use App\Support\Logging\RedactingFormatter;
use App\Support\Logging\RedactSensitiveLogs;
use App\Support\Logging\RedactSensitiveQuery;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;

function redactLogFile(): string
{
    $path = sys_get_temp_dir() . '/redact-' . bin2hex(random_bytes(4)) . '.log';
    config(['logging.channels.redact_test' => [
        'driver' => 'single', 'path' => $path, 'level' => 'debug', 'replace_placeholders' => true,
        'tap' => config('logging.channels.single.tap'),
    ]]);

    return $path;
}

it('masks the secret parameters of a query string and nothing else', function () {
    $url = 'https://api.quinvite.com/oauth/google/callback?state=keep&code=AUTHCODE&token=T0K&signature=SIG&hash=H&password=p%40ss&page=2#frag';

    expect(RedactSensitiveQuery::in($url))->toBe('https://api.quinvite.com/oauth/google/callback?state=keep&code=[redacted]&token=[redacted]&signature=[redacted]&hash=[redacted]&password=[redacted]&page=2#frag')
        ->and(RedactSensitiveQuery::in('GET /i/x?CODE=abc"'))->toBe('GET /i/x?CODE=[redacted]"')
        ->and(RedactSensitiveQuery::in('{"url":"/a?x=1&token=abc&y=2"}'))->toBe('{"url":"/a?x=1&token=[redacted]&y=2"}')
        ->and(RedactSensitiveQuery::in('/a?x=1&amp;signature=abc&amp;y=2'))->toBe('/a?x=1&amp;signature=[redacted]&amp;y=2')
        // Not a query string: left alone.
        ->and(RedactSensitiveQuery::in('status code=429 retry after 5'))->toBe('status code=429 retry after 5')
        ->and(RedactSensitiveQuery::in('no equals sign here'))->toBe('no equals sign here');
});

it('keeps secrets out of the message, the context and the exception of a log line (B3-2)', function () {
    $path = redactLogFile();

    Log::channel('redact_test')->warning('Calling /verify?token=MSGTOKEN', ['url' => 'https://x.test/a?signature=CTXSIG', 'nested' => ['link' => '/b?password=CTXPASS&ok=1']]);
    Log::channel('redact_test')->error('failed', ['exception' => new RuntimeException('GET https://x.test/invite?code=EXCCODE&hash=EXCHASH returned 500')]);

    $log = file_get_contents($path);
    unlink($path);

    foreach (['MSGTOKEN', 'CTXSIG', 'CTXPASS', 'EXCCODE', 'EXCHASH'] as $secret) {
        expect($log)->not->toContain($secret);
    }
    expect($log)->toContain('token=[redacted]')->toContain('ok=1')->toContain('returned 500');
});

it('keeps secrets out of what the exception handler reports', function () {
    $path = redactLogFile();
    config(['logging.default' => 'redact_test']);

    app(ExceptionHandler::class)->report(new RuntimeException('Client error: `GET https://hooks.test/cb?token=HANDLERTOKEN&signature=HANDLERSIG`'));

    $log = file_get_contents($path);
    unlink($path);

    expect($log)->not->toContain('HANDLERTOKEN')->not->toContain('HANDLERSIG')->toContain('Client error');
});

it('taps every real channel', function () {
    foreach (['single', 'daily', 'stderr', 'syslog', 'errorlog', 'papertrail'] as $channel) {
        expect(config("logging.channels.$channel.tap"))->toBe([RedactSensitiveLogs::class], $channel);
    }
});

it('masks batches written by a handler too', function () {
    $formatter = new RedactingFormatter(new LineFormatter);
    $record = fn (string $m) => new LogRecord(new DateTimeImmutable, 'app', Level::Info, $m);

    $batch = $formatter->formatBatch([$record('/a?token=ONE'), $record('/b?code=TWO')]);

    expect($batch)->not->toContain('ONE')->not->toContain('TWO')->toContain('token=[redacted]');
});
