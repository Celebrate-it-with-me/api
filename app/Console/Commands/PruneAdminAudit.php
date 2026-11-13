<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use Illuminate\Console\Command;

class PruneAdminAudit extends Command
{
    protected $signature = 'admin:audit-prune {--years= : Keep this many years (default: config admin.audit_retention_years)}';

    protected $description = 'Delete staff audit log rows older than the retention period';

    public function handle(): int
    {
        $years = max(1, (int) ($this->option('years') ?: config('admin.audit_retention_years', 2)));

        // At the query level on purpose: the model refuses to delete rows one by one.
        $deleted = AdminAuditLog::query()->where('created_at', '<', now()->subYears($years))->toBase()->delete();

        $this->info("Deleted {$deleted} audit rows older than {$years} years.");

        return self::SUCCESS;
    }
}
