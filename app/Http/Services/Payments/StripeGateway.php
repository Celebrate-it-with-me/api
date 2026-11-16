<?php

namespace App\Http\Services\Payments;

use App\Models\EventPurchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe Checkout (hosted page, one payment). The amount always comes from the purchase row, never from the browser;
 * coupons are ours, so Stripe gets the final price. Secrets live in config only and are never logged.
 */
class StripeGateway implements PaymentGateway
{
    /** A Checkout session lives this long; Stripe accepts 30 minutes to 24 hours. Matches the coupon hold. */
    public const SESSION_MINUTES = 60;

    /** How far a webhook's timestamp may be from now. */
    public const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function __construct(private readonly StripeClient $client) {}

    /** The configured gateway. A missing key is an error, and a live key outside production is refused. */
    public static function fromConfig(): self
    {
        $key = (string) config('services.stripe.secret');

        if ($key === '') {
            throw new \RuntimeException('STRIPE_SECRET is not configured.');
        }
        if (! app()->environment('production') && preg_match('/^(sk|rk)_live_/', $key)) {
            throw new \RuntimeException('A live Stripe key cannot be used outside production.');
        }

        return new self(new StripeClient(array_filter([
            'api_key' => $key,
            'stripe_version' => config('services.stripe.api_version') ?: null,
            'max_network_retries' => 1,
        ])));
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function createSession(EventPurchase $purchase, string $successUrl, string $cancelUrl, array $context = []): array
    {
        $ref = (string) $purchase->id;
        $coupon = (string) ($context['coupon'] ?? '');
        $metadata = [
            'purchase_id' => $ref, 'event_id' => (string) $purchase->event_id, 'user_id' => (string) $purchase->user_id,
            'coupon' => $coupon, 'is_founder' => $purchase->is_founder ? '1' : '0',
        ];
        $expiresAt = ($purchase->created_at ?? now())->copy()->addMinutes(self::SESSION_MINUTES);
        $tax = (bool) config('services.stripe.automatic_tax');

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => array_filter([
                    'currency' => strtolower($purchase->currency),
                    'unit_amount' => (int) $purchase->amount_cents,
                    'tax_behavior' => $tax ? 'inclusive' : null,
                    'product_data' => [
                        'name' => 'Quinvite Premium — evento completo',
                        'description' => 'Lista $' . intdiv((int) $purchase->list_price_cents, 100) . ($coupon !== '' ? " · código {$coupon}" : ''),
                    ],
                ], fn ($v) => $v !== null),
            ]],
            'client_reference_id' => $ref,
            'metadata' => $metadata,
            'payment_intent_data' => [
                'metadata' => $metadata,
                'description' => "Quinvite · evento {$purchase->event_id} · compra {$purchase->id}",
            ],
            'locale' => ($context['locale'] ?? 'es') === 'en' ? 'en' : 'es',
            'success_url' => $successUrl . (str_contains($successUrl, '?') ? '&' : '?') . 'purchase=' . $ref,
            'cancel_url' => $cancelUrl . (str_contains($cancelUrl, '?') ? '&' : '?') . 'purchase=' . $ref,
            'expires_at' => $expiresAt->getTimestamp(),
            'billing_address_collection' => $tax ? 'required' : 'auto',
        ];
        if ($purchase->buyer?->email) {
            $params['customer_email'] = $purchase->buyer->email;
        }
        if ($tax) {
            $params['automatic_tax'] = ['enabled' => true];
        }

        $session = $this->call(fn () => $this->client->checkout->sessions->create($params, ['idempotency_key' => "checkout-{$purchase->id}"]));

        return [
            'id' => (string) $session->id,
            'url' => $session->url ?? null,
            'expiresAt' => isset($session->expires_at) ? Carbon::createFromTimestamp((int) $session->expires_at) : $expiresAt,
        ];
    }

    public function retrieveSession(string $sessionId): array
    {
        $s = $this->call(fn () => $this->client->checkout->sessions->retrieve($sessionId))->toArray();
        $intent = $s['payment_intent'] ?? null;

        return [
            'id' => (string) ($s['id'] ?? $sessionId),
            'state' => (string) ($s['status'] ?? ''),
            'paymentStatus' => (string) ($s['payment_status'] ?? ''),
            'paymentId' => $intent ? (string) (is_array($intent) ? $intent['id'] : $intent) : null,
            'amount' => isset($s['amount_total']) ? (int) $s['amount_total'] : null,
            'currency' => isset($s['currency']) ? strtolower((string) $s['currency']) : null,
            'tax' => isset($s['total_details']['amount_tax']) ? (int) $s['total_details']['amount_tax'] : null,
            'url' => $s['url'] ?? null,
        ];
    }

    public function retrievePayment(string $paymentId): array
    {
        $refunds = $this->call(fn () => $this->client->refunds->all(['payment_intent' => $paymentId, 'limit' => 100]))->toArray()['data'] ?? [];
        $succeeded = array_values(array_filter($refunds, fn ($r) => ($r['status'] ?? null) === 'succeeded'));
        $disputes = $this->call(fn () => $this->client->disputes->all(['payment_intent' => $paymentId, 'limit' => 1]))->toArray()['data'] ?? [];

        return [
            'amountRefunded' => (int) array_sum(array_map(fn ($r) => (int) ($r['amount'] ?? 0), $succeeded)),
            'refundId' => $succeeded[0]['id'] ?? null,
            'disputeId' => $disputes[0]['id'] ?? null,
            'disputeStatus' => $disputes[0]['status'] ?? null,
        ];
    }

    public function expireSession(string $sessionId): void
    {
        $this->call(fn () => $this->client->checkout->sessions->expire($sessionId));
    }

    public function refund(EventPurchase $purchase): ?string
    {
        if (blank($purchase->gateway_payment_id)) {
            throw new PaymentGatewayException('The purchase has no payment to refund.');
        }

        $refund = $this->call(fn () => $this->client->refunds->create([
            'payment_intent' => $purchase->gateway_payment_id,
            'reason' => 'requested_by_customer',
            'metadata' => ['purchase_id' => (string) $purchase->id, 'reason' => substr((string) $purchase->refund_reason, 0, 400)],
        ], ['idempotency_key' => "refund-{$purchase->id}"]));

        return (string) $refund->id;
    }

    public function parseWebhook(Request $request): GatewayEvent
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            throw new WebhookNotConfiguredException('STRIPE_WEBHOOK_SECRET is not configured.');
        }

        $header = (string) $request->header('Stripe-Signature');
        if ($header === '') {
            throw new InvalidWebhookException('Missing signature.');
        }

        // The raw body, exactly as sent: re-encoding the JSON would break the signature.
        try {
            $event = Webhook::constructEvent($request->getContent(), $header, $secret, self::WEBHOOK_TOLERANCE_SECONDS);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new InvalidWebhookException('Invalid signature.');
        }

        return $this->normalize($event);
    }

    private function normalize(Event $event): GatewayEvent
    {
        $type = (string) $event->type;
        // A plain array: absent fields are simply null (reading a missing property of a Stripe object raises a notice).
        $o = $event->data->object->toArray();
        $base = ['id' => (string) $event->id, 'type' => $type];
        $str = fn ($v) => $v === null || $v === '' ? null : (string) (is_array($v) ? ($v['id'] ?? '') : $v);
        $currency = fn () => isset($o['currency']) ? strtolower((string) $o['currency']) : null;

        switch ($type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
            case 'checkout.session.async_payment_failed':
            case 'checkout.session.expired':
                $kind = match ($type) {
                    'checkout.session.async_payment_failed' => GatewayEvent::FAILED,
                    'checkout.session.expired' => GatewayEvent::EXPIRED,
                    default => ($o['payment_status'] ?? null) === 'paid' ? GatewayEvent::PAID : GatewayEvent::UNPAID,
                };

                return new GatewayEvent(
                    ...$base, kind: $kind, sessionId: $str($o['id'] ?? null), reference: $str($o['client_reference_id'] ?? null),
                    metadataReference: $str($o['metadata']['purchase_id'] ?? null), paymentId: $str($o['payment_intent'] ?? null),
                    amount: isset($o['amount_total']) ? (int) $o['amount_total'] : null, currency: $currency(),
                    taxCents: isset($o['total_details']['amount_tax']) ? (int) $o['total_details']['amount_tax'] : null,
                );

            case 'charge.refunded':
                return new GatewayEvent(
                    ...$base, kind: GatewayEvent::REFUNDED, paymentId: $str($o['payment_intent'] ?? null),
                    metadataReference: $str($o['metadata']['purchase_id'] ?? null),
                    amount: (int) ($o['amount'] ?? 0), amountRefunded: (int) ($o['amount_refunded'] ?? 0), currency: $currency(),
                );

            case 'charge.dispute.created':
            case 'charge.dispute.closed':
                return new GatewayEvent(
                    ...$base, kind: $type === 'charge.dispute.created' ? GatewayEvent::DISPUTE_CREATED : GatewayEvent::DISPUTE_CLOSED,
                    paymentId: $str($o['payment_intent'] ?? null), disputeId: $str($o['id'] ?? null), disputeStatus: $str($o['status'] ?? null),
                );

            default:
                return new GatewayEvent(...$base, kind: GatewayEvent::IGNORED);
        }
    }

    /** @template T @param  \Closure(): T  $call @return T */
    private function call(\Closure $call)
    {
        try {
            return $call();
        } catch (ApiErrorException $e) {
            // The message of a Stripe error carries no card data; the request itself is never logged.
            throw new PaymentGatewayException('Stripe error: ' . $e->getMessage(), 0, $e);
        }
    }
}
