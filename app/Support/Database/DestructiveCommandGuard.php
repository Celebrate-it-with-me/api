<?php

namespace App\Support\Database;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

/**
 * Lock against wiping a protected database (the shared development one) by mistake.
 *
 * Listens to CommandStarting, so it also covers Artisan::call() and RefreshDatabase. It only blocks
 * migrate:fresh, migrate:refresh, migrate:reset and db:wipe when the target database name is in
 * `database.protected_names` (env PROTECTED_DB_NAMES). Override on purpose with ALLOW_DESTRUCTIVE_DB=1
 * in the environment of the command.
 *
 * It complements DB::prohibitDestructiveCommands(), which blocks the same commands in production
 * regardless of the database name; this guard works in any environment but only for listed names.
 */
class DestructiveCommandGuard
{
    public const COMMANDS = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'];

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::COMMANDS, true) || self::overridden()) {
            return;
        }

        $connection = $event->input->getParameterOption('--database') ?: config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === '' || ! in_array($database, (array) config('database.protected_names', []), true)) {
            return;
        }

        throw new RuntimeException(
            "BLOQUEADO: '{$event->command}' borraría la base protegida '{$database}'. "
            . 'Si de verdad lo quieres, ejecuta con ALLOW_DESTRUCTIVE_DB=1 en el entorno del comando '
            . "(docker exec -e ALLOW_DESTRUCTIVE_DB=1 cwm_api php artisan {$event->command}).\n"
            . "BLOCKED: '{$event->command}' would wipe the protected database '{$database}'. "
            . 'Set ALLOW_DESTRUCTIVE_DB=1 in the command environment to override deliberately, '
            . 'or change the PROTECTED_DB_NAMES list.'
        );
    }

    private static function overridden(): bool
    {
        $value = getenv('ALLOW_DESTRUCTIVE_DB');
        if ($value === false) {
            $value = $_SERVER['ALLOW_DESTRUCTIVE_DB'] ?? $_ENV['ALLOW_DESTRUCTIVE_DB'] ?? '';
        }

        return in_array(strtolower((string) $value), ['1', 'true'], true);
    }
}
