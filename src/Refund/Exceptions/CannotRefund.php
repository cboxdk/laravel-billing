<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Exceptions;

use Cbox\Billing\Money\Money;
use RuntimeException;

/**
 * Raised when a refund is refused up front — the deny-by-default guard. A refund can
 * never exceed what was charged, never target an unissued invoice, never move a
 * non-positive amount, and never cross the invoice's currency.
 */
class CannotRefund extends RuntimeException
{
    public static function unissuedInvoice(): self
    {
        return new self('Cannot refund an unissued invoice; issue it first.');
    }

    public static function nonPositiveAmount(): self
    {
        return new self('A refund must move a positive amount.');
    }

    public static function currencyMismatch(string $requested, string $invoice): self
    {
        return new self(sprintf(
            'Refund currency [%s] does not match the invoice currency [%s].',
            $requested,
            $invoice,
        ));
    }

    public static function exceedsCharged(Money $attempted, Money $charged, Money $alreadyRefunded): self
    {
        return new self(sprintf(
            'Refund of %s would exceed the amount charged: %s charged, %s already refunded.',
            $attempted,
            $charged,
            $alreadyRefunded,
        ));
    }
}
