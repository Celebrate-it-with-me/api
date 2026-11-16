<?php

namespace App\Http\Services\Payments;

use App\Models\EventPurchase;
use Illuminate\Http\Request;

/** A way of charging for an event. The purchase row is created first; the gateway only starts the payment. */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Start the payment.
     *
     * @param  array{locale?: string, coupon?: string|null}  $context
     * @return array{id: string, url: string|null, expiresAt: \DateTimeInterface|null}
     *
     * @throws PaymentGatewayException when the provider fails
     */
    public function createSession(EventPurchase $purchase, string $successUrl, string $cancelUrl, array $context = []): array;

    /**
     * The provider's view of a session. `state` is open | complete | expired; `paymentStatus` is paid | unpaid.
     *
     * @return array{id: string, state: string, paymentStatus: string, paymentId: string|null, amount: int|null, currency: string|null, url: string|null}
     *
     * @throws PaymentGatewayException
     */
    public function retrieveSession(string $sessionId): array;

    /** Close an open session so it can no longer be paid. @throws PaymentGatewayException */
    public function expireSession(string $sessionId): void;

    /** Give the money back (idempotent per purchase). Returns the provider's refund id, if it has one. @throws PaymentGatewayException */
    public function refund(EventPurchase $purchase): ?string;

    /**
     * Verify the signature over the raw body and normalize the event.
     *
     * @throws InvalidWebhookException when the signature or the body is not valid (400)
     * @throws WebhookNotConfiguredException when there is no secret to verify with (503)
     */
    public function parseWebhook(Request $request): GatewayEvent;
}
