<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Exceptions;

use Cbox\Billing\Catalog\ValueObjects\PercentagePrice;
use InvalidArgumentException;

/**
 * A {@see PercentagePrice} that cannot be priced unambiguously: a rate it cannot hold exactly, a
 * tier set out of order, a fee, minimum or maximum in another currency or below zero, or a negative
 * volume. Deny-by-default, never a silent zero.
 */
class MalformedPercentagePrice extends InvalidArgumentException
{
    public static function rate(string $percent): self
    {
        return new self("A percentage must be a decimal from 0 to 100 with at most four decimals, [{$percent}] given.");
    }

    public static function partsPerMillion(int $value): self
    {
        return new self("A rate must be from 0 to 1000000 parts per million, [{$value}] given.");
    }

    public static function noTiers(): self
    {
        return new self('A percentage price needs at least one rate.');
    }

    public static function unorderedBounds(): self
    {
        return new self('Percentage tiers must have strictly ascending, positive upper bounds, with only the last tier unbounded (upTo = null).');
    }

    public static function currency(string $currency): self
    {
        return new self("A percentage price's currency must be an upper-case ISO 4217 code, [{$currency}] given.");
    }

    public static function currencyMismatch(string $expected, string $given): self
    {
        return new self("A percentage price in [{$expected}] cannot take an amount in [{$given}].");
    }

    public static function negativeAmount(string $what): self
    {
        return new self("A percentage price's {$what} must not be negative.");
    }

    public static function minimumAboveMaximum(): self
    {
        return new self("A percentage price's minimum must not be above its maximum.");
    }

    public static function negativeQuantity(string $what, int $value): self
    {
        return new self("A percentage price cannot charge a negative {$what}, [{$value}] given.");
    }

    public static function missingTerms(string $priceId): self
    {
        return new self("Price [{$priceId}] uses the percentage model but carries no percentage terms.");
    }
}
