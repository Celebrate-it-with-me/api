<?php

use App\Support\Database\DestructiveCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function guardEvent(string $command, array $input = []): CommandStarting
{
    return new CommandStarting($command, new ArrayInput($input), new NullOutput);
}

beforeEach(function () {
    putenv('ALLOW_DESTRUCTIVE_DB');
    unset($_SERVER['ALLOW_DESTRUCTIVE_DB'], $_ENV['ALLOW_DESTRUCTIVE_DB']);
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.database' => 'guard_protected_db',
        'database.protected_names' => ['guard_protected_db'],
    ]);
});

afterEach(function () {
    putenv('ALLOW_DESTRUCTIVE_DB');
});

it('blocks every destructive command on a protected database', function (string $command) {
    expect(fn () => (new DestructiveCommandGuard)->handle(guardEvent($command)))
        ->toThrow(RuntimeException::class, 'guard_protected_db');
})->with(DestructiveCommandGuard::COMMANDS);

it('lets them through with ALLOW_DESTRUCTIVE_DB=1', function (string $command) {
    putenv('ALLOW_DESTRUCTIVE_DB=1');
    (new DestructiveCommandGuard)->handle(guardEvent($command));
    expect(true)->toBeTrue();
})->with(DestructiveCommandGuard::COMMANDS);

it('lets them through on any other database', function (string $command) {
    config(['database.connections.pgsql.database' => 'testing_atlas']);
    (new DestructiveCommandGuard)->handle(guardEvent($command));
    expect(true)->toBeTrue();
})->with(DestructiveCommandGuard::COMMANDS);

it('ignores non destructive commands', function (string $command) {
    foreach (['migrate', 'db:seed', 'migrate:status'] as $c) {
        (new DestructiveCommandGuard)->handle(guardEvent($c));
    }
    expect(true)->toBeTrue();
})->with(['x']);

it('respects several protected names', function () {
    config([
        'database.protected_names' => ['foo', 'staging_db'],
        'database.connections.pgsql.database' => 'staging_db',
    ]);
    expect(fn () => (new DestructiveCommandGuard)->handle(guardEvent('db:wipe')))->toThrow(RuntimeException::class);

    config(['database.connections.pgsql.database' => 'guard_protected_db']);
    (new DestructiveCommandGuard)->handle(guardEvent('db:wipe'));
});

it('checks the --database option connection', function () {
    config(['database.connections.other' => ['driver' => 'pgsql', 'database' => 'guard_protected_db']]);
    config(['database.connections.pgsql.database' => 'testing_x']);
    expect(fn () => (new DestructiveCommandGuard)->handle(guardEvent('migrate:fresh', ['--database' => 'other'])))
        ->toThrow(RuntimeException::class);
});

it('is registered as a CommandStarting listener and aborts when the event is dispatched', function () {
    // Laravel does not reroute Symfony command events while running unit tests, so dispatch the event by hand.
    expect(Event::hasListeners(CommandStarting::class))->toBeTrue()
        ->and(fn () => Event::dispatch(guardEvent('db:wipe')))->toThrow(RuntimeException::class, 'BLOQUEADO');
});

it('protects celebrateitwithme by default and parses a comma separated list', function () {
    putenv('PROTECTED_DB_NAMES');
    unset($_ENV['PROTECTED_DB_NAMES'], $_SERVER['PROTECTED_DB_NAMES']);
    expect((require config_path('database.php'))['protected_names'])->toContain('celebrateitwithme');

    $_ENV['PROTECTED_DB_NAMES'] = $_SERVER['PROTECTED_DB_NAMES'] = ' a , b ,,c';
    putenv('PROTECTED_DB_NAMES= a , b ,,c');
    try {
        expect((require config_path('database.php'))['protected_names'])->toBe(['a', 'b', 'c']);
    } finally {
        putenv('PROTECTED_DB_NAMES');
        unset($_ENV['PROTECTED_DB_NAMES'], $_SERVER['PROTECTED_DB_NAMES']);
    }
});
