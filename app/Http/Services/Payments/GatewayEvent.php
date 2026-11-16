<?php

namespace App\Http\Services\Payments;

/** A webhook event reduced to what the purchase flow needs (no personal data). */
final class GatewayEvent
{
    public const PAID = 'paid';

    public const UNPAID = 'unpaid';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const REFUNDED = 'refunded';

    public const DISPUTE_CREATED = 'dispute_created';

    public const DISPUTE_CLOSED = 'dispute_closed';

    public const IGNORED = 'ignored';

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $kind,
        public readonly ?string $sessionId = null,
        /** The purchase id the provider echoes back (client_reference_id). */
        public readonly ?string $reference = null,
        /** The purchase id from our metadata, to cross-check with the reference. */
        public readonly ?string $metadataReference = null,
        public readonly ?string $paymentId = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly ?int $amountRefunded = null,
        public readonly ?string $disputeId = null,
        public readonly ?string $disputeStatus = null,
    ) {}
}
