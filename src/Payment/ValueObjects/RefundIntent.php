<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * A request to return an amount previously captured, tied to what it reverses (a
 * credit note number) and — when the gateway needs it — the original charge's gateway
 * reference. Gateway-agnostic: gateways read the amount and reference.
 *
 * `idempotencyKey` scopes the refund at the gateway so a retry or a re-delivered
 * webhook collapses to a single money movement rather than refunding twice.
 */
readonly class RefundIntent
{
    public function __construct(
        public string $id,
        public Money $amount,
        public string $reference,
        public string $idempotencyKey,
        public ?string $originalGatewayReference = null,
    ) {}
}
