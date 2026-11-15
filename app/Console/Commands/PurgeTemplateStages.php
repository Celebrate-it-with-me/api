<?php

namespace App\Console\Commands;

use App\Http\Services\Admin\Templates\Packages\PackageStage;
use Illuminate\Console\Command;

class PurgeTemplateStages extends Command
{
    protected $signature = 'templates:purge-stages';

    protected $description = 'Remove the template package uploads that expired without being installed';

    public function handle(PackageStage $stage): int
    {
        $this->info('Removed ' . $stage->purgeExpired() . ' expired package upload(s).');

        return self::SUCCESS;
    }
}
