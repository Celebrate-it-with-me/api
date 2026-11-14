<?php

namespace App\Console\Commands;

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\System\TwoFactorEnforcement;
use Illuminate\Console\Command;

/** Emergency exit for the "require two-factor for all staff" switch, usable from the server when nobody can open the panel. */
class RequireStaffTwoFactor extends Command
{
    protected $signature = 'admin:require-2fa {action : on, off or status}';

    protected $description = 'Turn the "require two-factor for all staff" switch on or off, or show its status';

    public function handle(TwoFactorEnforcement $enforcement, AdminAudit $audit): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['on', 'off', 'status'], true)) {
            $this->error('Use on, off or status.');

            return self::INVALID;
        }

        if ($action !== 'status') {
            $enforcement->set($action === 'on', null);
            $audit->record('system.require_two_factor_' . $action, ['system', null], [
                'reason' => 'Console command admin:require-2fa',
                'after' => ['enabled' => $action === 'on'],
            ]);
        }

        $this->line($enforcement->enabled() ? 'Two-factor is required for all staff.' : 'Two-factor is not required by the switch.');
        if (config('admin.require_two_factor_superadmin')) {
            $this->warn('ADMIN_REQUIRE_2FA_SUPERADMIN is true: superadmins stay forced whatever the switch says (change the environment).');
        }

        return self::SUCCESS;
    }
}
