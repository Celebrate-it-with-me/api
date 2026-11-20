<?php

namespace App\Console\Commands;

use App\Support\ProductionSafetyCheck;
use Illuminate\Console\Command;

class AppDoctor extends Command
{
    protected $signature = 'app:doctor {--strict : Also fail (exit 1) when there are warnings}';

    protected $description = 'Check the environment for settings that are unsafe in production (fails if any, only when APP_ENV=production; --strict also fails on warnings)';

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

        // Warnings do not stop a boot; they are things that misbehave on a deployed server (production, or staging on a real URL).
        $warnings = ProductionSafetyCheck::warnings();
        foreach ($warnings as $warning) {
            $this->line('<fg=yellow>WARNING</> ' . $warning);
        }
        if (! $warnings) {
            $this->info('No warnings.');
        }

        if ($problems && app()->environment('production')) {
            return self::FAILURE;
        }

        return $warnings && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
