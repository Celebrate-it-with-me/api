<?php

namespace App\Http\Services\Payments;

use App\Models\EventPurchase;
use Illuminate\Support\Str;

/** Stands in for Stripe until there is an account: the "payment page" is a local URL, confirmed by a signed webhook. */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createSession(EventPurchase $purchase, string $successUrl, string $cancelUrl): array
    {
        $id = 'fake_'.Str::random(24);

        return ['id' => $id, 'url' => $successUrl.(str_contains($successUrl, '?') ? '&' : '?').'session='.$id];
    }

    public function refund(EventPurchase $purchase): void {}

    /** Signature the "gateway" puts on its webhook calls. */
    public static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('services.payments.webhook_secret'));
    }
}
