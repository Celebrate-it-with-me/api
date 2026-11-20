<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes rows of tables that grow without bound once nobody needs them: webhook dedupe keys, orphan WhatsApp statuses,
 * processed payment events, ended impersonation sessions, dead admin tokens and old failed jobs. Windows live in
 * config/retention.php. `--dry-run` only counts. Never touches rows that are still in use (open impersonations, live tokens).
 */
class PruneStaleData extends Command
{
    protected $signature = 'data:prune {--dry-run : Only count what would be deleted}';

    protected $description = 'Delete old webhook keys, orphan WhatsApp statuses, payment events, ended impersonations, dead admin tokens and failed jobs';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $targets = [
            'whatsapp_inbound_events' => DB::table('whatsapp_inbound_events')->where('created_at', '<', now()->subDays($this->days('whatsapp_inbound_events_days'))),
            'whatsapp_pending_statuses' => DB::table('whatsapp_pending_statuses')->where('created_at', '<', now()->subDays($this->days('whatsapp_pending_statuses_days'))),
            'payment_events' => DB::table('payment_events')->where('received_at', '<', now()->subDays($this->days('payment_events_days'))),
            'impersonation_sessions' => DB::table('impersonation_sessions')->whereNotNull('ended_at')
                ->where('ended_at', '<', now()->subYears(max(1, (int) config('admin.audit_retention_years', 2)))),
            'admin tokens' => $this->deadAdminTokens(),
            'failed_jobs' => DB::table('failed_jobs')->where('failed_at', '<', now()->subDays($this->days('failed_jobs_days'))),
        ];

        foreach ($targets as $label => $query) {
            $count = $dry ? $query->count() : $query->delete();
            $this->line(($dry ? 'Would delete' : 'Deleted') . " $count from $label.");
        }

        return self::SUCCESS;
    }

    /** Admin tokens past their absolute life or idle limit (config admin.*): the request that would use them is already refused. */
    private function deadAdminTokens(): Builder
    {
        $max = max(1, (int) config('admin.token_max_days'));
        $idle = max(1, (int) config('admin.token_idle_hours'));

        return DB::table('personal_access_tokens')->where('client', 'admin')->where(function ($q) use ($max, $idle) {
            $q->where('created_at', '<=', now()->subDays($max))
                ->orWhereRaw('coalesce(last_used_at, created_at) <= ?', [now()->subHours($idle)]);
        });
    }

    private function days(string $key): int
    {
        return max(1, (int) config("retention.$key"));
    }
}
