<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * A request to collect an amount, tied to what it pays for (e.g. an invoice
 * number). Gateway-agnostic: gateways read the amount and reference.
 */
readonly class PaymentIntent
{
    public function __construct(
        public string $id,
        public Money $amount,
        public string $reference,
    ) {}
}
