<?php

namespace App\Http\Services\Payments;

use App\Models\EventPurchase;
use LogicException;

/** Zelle, cash, a gift: recorded by an admin. There is no session to start and nothing to give back automatically. */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function createSession(EventPurchase $purchase, string $successUrl, string $cancelUrl): array
    {
        throw new LogicException('Manual payments are recorded by an admin.');
    }

    public function refund(EventPurchase $purchase): void {}
}
