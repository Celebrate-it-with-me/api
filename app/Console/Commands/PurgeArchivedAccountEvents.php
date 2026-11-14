<?php

namespace App\Console\Commands;

use App\Http\Services\Account\EventPurger;
use App\Http\Services\Admin\AdminAudit;
use App\Models\Events;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes the events archived by an account deletion once their grace period is over (docs/plans/account-deletion-contract.md):
 * guests, data and uploaded files. Daily and idempotent: an event already purged is not found again.
 */
class PurgeArchivedAccountEvents extends Command
{
    protected $signature = 'account:purge-archived';

    protected $description = 'Delete the events archived after an account deletion whose purge date has passed';

    public function handle(EventPurger $purger): int
    {
        $purged = $failed = 0;

        Events::query()->accountArchived()->where('purge_after', '<=', now())->orderBy('id')->each(function (Events $event) use ($purger, &$purged, &$failed) {
            try {
                $purger->purge($event);
                $purged++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        });

        if ($purged > 0 || $failed > 0) {
            Log::info('account:purge-archived', ['purged' => $purged, 'failed' => $failed]);
            app(AdminAudit::class)->record('account.purge_archived', null, ['after' => ['purged_events' => $purged, 'failed' => $failed]]);
        }

        $this->info("Purged {$purged} archived events" . ($failed ? ", {$failed} failed." : '.'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
