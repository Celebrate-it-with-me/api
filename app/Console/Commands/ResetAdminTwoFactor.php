<?php

namespace App\Console\Commands;

use App\Http\Services\Admin\AdminAudit;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Emergency way back in for a staff member who lost both her authenticator and her recovery codes. It needs shell
 * access to the server, which is the point: no endpoint can do this to another person's account.
 */
class ResetAdminTwoFactor extends Command
{
    protected $signature = 'admin:two-factor-reset {email : Sign-in email of the staff member}';

    protected $description = 'Turn off two-factor for one staff member and close all her sessions (lost authenticator and recovery codes)';

    public function handle(AdminAudit $audit): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $user->adminTwoFactor()->delete();
        $closed = $user->tokens()->delete();
        $audit->record('account.two_factor_reset_cli', $user, ['actor' => null, 'after' => ['sessionsClosed' => $closed]]);

        $this->info("Two-factor turned off for {$user->email}; {$closed} sessions closed.");

        return self::SUCCESS;
    }
}
