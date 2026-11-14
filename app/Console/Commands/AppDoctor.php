<?php

namespace App\Console\Commands;

use App\Support\ProductionSafetyCheck;
use Illuminate\Console\Command;

class AppDoctor extends Command
{
    protected $signature = 'app:doctor';

    protected $description = 'Check the environment for settings that are unsafe in production (fails if any, only when APP_ENV=production)';

    public function handle(): int
    {
        if (! app()->environment('production')) {
            $this->info('Not production: nothing to enforce. Problems that would block a production boot:');
        }

        $problems = ProductionSafetyCheck::problems();
        foreach ($problems as $problem) {
            $this->warn($problem);
        }
        if (! $problems) {
            $this->info('No problems found.');
        }

        return $problems && app()->environment('production') ? self::FAILURE : self::SUCCESS;
    }
}
