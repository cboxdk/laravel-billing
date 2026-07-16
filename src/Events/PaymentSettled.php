<?php

declare(strict_types=1);

namespace Cbox\Billing\Events;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * A payment settled: the exactly-once webhook ingest ({@see WebhookIngest::ingest()})
 * claimed a settlement and applied the paid effect to the invoice. Fires once per
 * reference at the moment the effect is applied — a duplicate event id or a re-delivery
 * of an already-settled reference collapses to a no-op and does NOT fire this.
 *
 * Carries the settled `$reference` (the payment/invoice natural key the effect is
 * idempotent on), the settled `$amount` as {@see Money}, and the mapped
 * {@see PaymentResult} (whose `gatewayReference` echoes the gateway event id for
 * reconciliation).
 */
readonly class PaymentSettled
{
    public function __construct(
        public string $reference,
        public Money $amount,
        public PaymentResult $result,
    ) {}
}
