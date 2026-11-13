<?php

namespace App\Console\Commands;

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\System\MaintenanceMode;
use Illuminate\Console\Command;

/** Emergency exit for the soft maintenance mode, usable from the server when the admin panel cannot be reached. */
class SystemMaintenance extends Command
{
    protected $signature = 'system:maintenance {action : on, off or status} {--message= : Message shown while it is on}';

    protected $description = 'Turn the soft maintenance mode on or off, or show its status';

    public function handle(MaintenanceMode $maintenance, AdminAudit $audit): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['on', 'off', 'status'], true)) {
            $this->error('Use on, off or status.');

            return self::INVALID;
        }

        if ($action === 'status') {
            $this->line($maintenance->active() ? 'Maintenance is ON.' : 'Maintenance is off.');

            return self::SUCCESS;
        }

        $state = $action === 'on' ? $maintenance->enable($this->option('message') ?: null, null, null) : $maintenance->disable(null);
        $audit->record($action === 'on' ? 'system.maintenance_on' : 'system.maintenance_off', ['system', null], [
            'reason' => 'Console command system:maintenance',
            'after' => ['active' => $state['active']],
        ]);
        $this->info($action === 'on' ? 'Maintenance is ON (expires by itself in ' . MaintenanceMode::MAX_HOURS . ' h).' : 'Maintenance is off.');

        return self::SUCCESS;
    }
}
