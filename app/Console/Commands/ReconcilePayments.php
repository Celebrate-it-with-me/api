<?php

namespace App\Console\Commands;

use App\Http\Services\Payments\PaymentSettlement;
use App\Models\EventPurchase;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--min-age=10 : Minutes a purchase must have been pending} {--max-age=1440 : Minutes after which it is left alone}';

    protected $description = 'Ask the payment provider about checkouts still pending and apply the answer (a lost or late webhook).';

    public function handle(PaymentSettlement $settlement): int
    {
        $settled = 0;
        $pending = EventPurchase::query()->where('status', 'pending')->where('gateway', 'stripe')->whereNotNull('gateway_session_id')
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('min-age')))
            ->where('created_at', '>=', now()->subMinutes((int) $this->option('max-age')));

        $pending->orderBy('id')->each(function (EventPurchase $purchase) use ($settlement, &$settled) {
            try {
                if ($settlement->reconcile($purchase, force: true)->status !== 'pending') {
                    $settled++;
                }
            } catch (\Throwable $e) {
                report($e); // one broken purchase must not stop the rest
            }
        });

        $this->info("Reconciled: {$settled} settled.");

        return self::SUCCESS;
    }
}
