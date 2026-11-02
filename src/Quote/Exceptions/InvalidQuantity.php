<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\Exceptions;

use Cbox\Billing\Quote\ValueObjects\Quantity;
use InvalidArgumentException;

/**
 * A line quantity could not be turned into an honest {@see Quantity} — deny-by-default
 * at the edge rather than pricing a line with a negative, zero, rounded-away or
 * unparseable amount.
 */
final class InvalidQuantity extends InvalidArgumentException
{
    public static function nonPositive(string $given): self
    {
        return new self("A quantity must be greater than zero; got [{$given}].");
    }

    public static function malformed(string $given): self
    {
        return new self("A quantity must be a plain decimal number such as 12 or 12.5; got [{$given}].");
    }

    public static function tooPrecise(string $given): self
    {
        return new self("A quantity supports at most three decimals and is never rounded; got [{$given}].");
    }

    public static function tooLarge(string $given): self
    {
        return new self("A quantity must not exceed 999999.999; got [{$given}].");
    }

    public static function notWhole(string $given): self
    {
        return new self("The quantity [{$given}] is fractional and cannot be used as a whole number.");
    }
}
