<?php

namespace App\Http\Services\Payments;

use App\Models\EventPurchase;

/** A way of charging for an event. The purchase row is created first; the gateway only starts the payment. */
interface PaymentGateway
{
    public function name(): string;

    /** Start the payment; returns the session id and the URL the buyer is sent to (null when there is none). */
    public function createSession(EventPurchase $purchase, string $successUrl, string $cancelUrl): array;

    /** Give the money back. */
    public function refund(EventPurchase $purchase): void;
}
