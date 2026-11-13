<?php

namespace App\Console\Commands;

use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Models\ImpersonationSession;
use Illuminate\Console\Command;

class CloseExpiredImpersonations extends Command
{
    protected $signature = 'impersonation:close-expired';

    protected $description = 'Close impersonation sessions whose token expired, and tell the users';

    public function handle(ImpersonationService $service): int
    {
        $closed = 0;
        ImpersonationSession::query()->whereNull('ended_at')->where('expires_at', '<=', now())->orderBy('id')
            ->each(function (ImpersonationSession $session) use ($service, &$closed) {
                $service->finish($session, 'expired');
                $closed++;
            });

        $this->info("Closed {$closed} expired impersonation sessions.");

        return self::SUCCESS;
    }
}
