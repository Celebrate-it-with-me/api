<?php

namespace App\Http\Services\Payments;

use App\Models\EventPurchase;
use Illuminate\Http\Request;
use LogicException;

/** Zelle, cash, a gift: recorded by an admin. There is no session to start and nothing to give back automatically. */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function createSession(EventPurchase $purchase, string $successUrl, string $cancelUrl, array $context = []): array
    {
        throw new LogicException('Manual payments are recorded by an admin.');
    }

    public function retrieveSession(string $sessionId): array
    {
        throw new LogicException('Manual payments have no session.');
    }

    public function retrievePayment(string $paymentId): array
    {
        throw new LogicException('Manual payments have no gateway payment.');
    }

    public function expireSession(string $sessionId): void {}

    public function refund(EventPurchase $purchase): ?string
    {
        return null;
    }

    public function parseWebhook(Request $request): GatewayEvent
    {
        throw new InvalidWebhookException('Manual payments have no webhook.');
    }
}
