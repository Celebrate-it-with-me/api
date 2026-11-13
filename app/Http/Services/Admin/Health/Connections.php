<?php

namespace App\Http\Services\Admin\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Do the database, Redis, storage and mail answer? Each check only says ok / error / unknown and how long it took:
 * errors are never echoed (they can carry hosts and credentials) and no configuration values are shown.
 */
class Connections
{
    public function report(): array
    {
        return [
            $this->check('database', 'Base de datos', (string) config('database.default'), function () {
                DB::select('select 1');
            }),
            $this->check('redis', 'Redis', 'redis', function () {
                Redis::connection()->ping();
            }),
            $this->check('storage', 'Almacenamiento', (string) config('filesystems.default'), function () {
                Storage::disk()->exists('.health-probe');
            }),
            $this->mail(),
        ];
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        $config = (array) config("mail.mailers.{$mailer}", []);
        $transport = (string) ($config['transport'] ?? $mailer);

        if ($transport !== 'smtp') {
            // log / array / ses...: nothing we can open without sending a message.
            return $this->result('mail', 'Correo', $transport, 'unknown', null);
        }

        return $this->check('mail', 'Correo', 'smtp', function () use ($config) {
            $socket = @fsockopen((string) ($config['host'] ?? ''), (int) ($config['port'] ?? 25), $code, $message, 2.0);
            if (! $socket) {
                throw new \RuntimeException('unreachable');
            }
            fclose($socket);
        });
    }

    private function check(string $key, string $label, string $driver, callable $probe): array
    {
        $start = microtime(true);
        try {
            $probe();
            $status = 'ok';
        } catch (Throwable) {
            $status = 'error';
        }

        return $this->result($key, $label, $driver, $status, (int) round((microtime(true) - $start) * 1000));
    }

    private function result(string $key, string $label, string $driver, string $status, ?int $ms): array
    {
        return ['key' => $key, 'label' => $label, 'driver' => $driver, 'status' => $status, 'latencyMs' => $ms];
    }
}
