<?php

namespace App\Http\Services\Payments;

use App\Models\EventPurchase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Stands in for Stripe until there is an account: the "payment page" is a local URL, confirmed by a signed webhook. */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createSession(EventPurchase $purchase, string $successUrl, string $cancelUrl, array $context = []): array
    {
        $id = 'fake_' . Str::random(24);

        return ['id' => $id, 'url' => $successUrl . (str_contains($successUrl, '?') ? '&' : '?') . 'session=' . $id, 'expiresAt' => null];
    }

    public function retrieveSession(string $sessionId): array
    {
        return ['id' => $sessionId, 'state' => 'open', 'paymentStatus' => 'unpaid', 'paymentId' => null, 'amount' => null, 'currency' => null, 'url' => null];
    }

    public function expireSession(string $sessionId): void {}

    public function refund(EventPurchase $purchase): ?string
    {
        return null;
    }

    /** `{type: payment.succeeded, session, payment?, amount?, currency?}` signed with X-Signature; the event id is the body's hash. */
    public function parseWebhook(Request $request): GatewayEvent
    {
        $payload = $request->getContent();

        if (blank(config('services.payments.webhook_secret'))) {
            throw new WebhookNotConfiguredException('Payments webhook secret is not configured.');
        }
        if (! hash_equals(self::sign($payload), (string) $request->header('X-Signature'))) {
            throw new InvalidWebhookException('Invalid signature.');
        }

        $data = json_decode($payload, true);
        if (! is_array($data)) {
            throw new InvalidWebhookException('Invalid body.');
        }

        $type = (string) ($data['type'] ?? '');

        return new GatewayEvent(
            id: 'fake_' . hash('sha256', $payload),
            type: $type,
            kind: $type === 'payment.succeeded' ? GatewayEvent::PAID : GatewayEvent::IGNORED,
            sessionId: isset($data['session']) ? (string) $data['session'] : null,
            paymentId: isset($data['payment']) ? (string) $data['payment'] : null,
            amount: isset($data['amount']) ? (int) $data['amount'] : null,
            currency: isset($data['currency']) ? strtolower((string) $data['currency']) : null,
        );
    }

    /** Signature the "gateway" puts on its webhook calls. */
    public static function sign(string $payload): string
    {
        abort_if(blank(config('services.payments.webhook_secret')), 503, 'Payments webhook secret is not configured.');

        return hash_hmac('sha256', $payload, (string) config('services.payments.webhook_secret'));
    }
}
