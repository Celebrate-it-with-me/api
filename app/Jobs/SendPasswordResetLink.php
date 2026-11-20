<?php

namespace App\Jobs;

use App\Events\ResetPasswordEvent;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks the account up and sends the reset link, out of the request. `forgot-password` queues this for ANY address
 * (registered or not) so the response time never tells whether the account exists; an unknown address just ends here.
 */
class SendPasswordResetLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $email) {}

    public function handle(): void
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($this->email)])->first();

        if ($user) {
            ResetPasswordEvent::dispatch($user);
        }
    }
}
