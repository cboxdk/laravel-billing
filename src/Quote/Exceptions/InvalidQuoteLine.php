<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\Exceptions;

use Cbox\Billing\Quote\DefaultQuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use InvalidArgumentException;

/**
 * A quote line was requested with an input that cannot produce an honest total —
 * deny-by-default at the edge rather than multiplying a price by a bad quantity or
 * discovering a currency clash only when summing totals.
 *
 * Raised by {@see LineInput} for a non-positive quantity (a negative quantity would
 * flip a flat/per-unit price into a negative charge), and by
 * {@see DefaultQuoteBuilder} when a quote mixes currencies across its lines (which
 * would otherwise surface as a late brick/money currency-mismatch while summing).
 */
class InvalidQuoteLine extends InvalidArgumentException
{
    public static function nonPositiveQuantity(int $quantity): self
    {
        return new self("A quote line quantity must be a positive integer; got [{$quantity}].");
    }

    public static function mixedCurrency(string $expected, string $found): self
    {
        return new self("All quote lines must share one currency; expected [{$expected}] but a line is priced in [{$found}].");
    }
}
