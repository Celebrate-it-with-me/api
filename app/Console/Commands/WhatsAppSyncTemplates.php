<?php

namespace App\Console\Commands;

use App\Http\Services\WhatsApp\TemplateSync;
use App\Http\Services\WhatsApp\WhatsAppException;
use App\Http\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;

class WhatsAppSyncTemplates extends Command
{
    protected $signature = 'whatsapp:sync-templates';

    protected $description = 'Pull the review status of the WhatsApp templates from Meta (meta driver only)';

    public function handle(WhatsAppService $whatsapp, TemplateSync $sync): int
    {
        if ($whatsapp->driverName() !== 'meta' || ! $whatsapp->isAvailable()) {
            $this->line('Nothing to sync: the meta driver is not configured.');

            return self::SUCCESS;
        }

        try {
            $sync->run();
        } catch (WhatsAppException $e) {
            $this->error('Sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->info('Templates synced.');

        return self::SUCCESS;
    }
}
