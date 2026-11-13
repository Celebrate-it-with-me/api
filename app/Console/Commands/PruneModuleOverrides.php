<?php

namespace App\Console\Commands;

use App\Models\EventModuleOverride;
use Illuminate\Console\Command;

class PruneModuleOverrides extends Command
{
    protected $signature = 'modules:prune-overrides';

    protected $description = 'Delete module exceptions that expired more than 30 days ago';

    public function handle(): int
    {
        $deleted = EventModuleOverride::query()->where('expires_at', '<', now()->subDays(30))->delete();

        $this->info("Deleted {$deleted} expired module overrides.");

        return self::SUCCESS;
    }
}
