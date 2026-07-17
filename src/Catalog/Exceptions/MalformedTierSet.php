<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Exceptions;

use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Pricing\TierCalculator;
use InvalidArgumentException;

/**
 * A tiered price was configured with a tier set that cannot be priced
 * unambiguously — deny-by-default rather than silently returning a zero or a
 * wrong amount. Raised by {@see TierCalculator} for an
 * empty, mis-ordered, negatively-priced, or gap-having tier set, a missing package
 * size/block price, or a quantity no tier covers.
 */
class MalformedTierSet extends InvalidArgumentException
{
    public static function empty(PricingModel $model): self
    {
        return new self("Pricing model [{$model->value}] requires at least one price tier.");
    }

    public static function notTiered(PricingModel $model): self
    {
        return new self("Pricing model [{$model->value}] is not a tiered model and cannot be priced from tiers.");
    }

    public static function unorderedBounds(): self
    {
        return new self('Price tiers must have strictly ascending, positive upper bounds, with only the last tier unbounded (upTo = null).');
    }

    public static function negativeAmount(): self
    {
        return new self('Price tier amounts (unit and flat) must not be negative.');
    }

    public static function currencyMismatch(): self
    {
        return new self('All price tiers in a set must share one currency.');
    }

    public static function negativeQuantity(int $quantity): self
    {
        return new self("Cannot price a negative quantity [{$quantity}].");
    }

    public static function uncovered(int $quantity): self
    {
        return new self("No price tier covers quantity [{$quantity}]; the final tier must be unbounded (upTo = null) to accept it.");
    }

    public static function packageSize(?int $size): self
    {
        $shown = $size === null ? 'null' : (string) $size;

        return new self("Package pricing requires a positive package size; got [{$shown}].");
    }

    public static function missingBlockPrice(): self
    {
        return new self('Package pricing requires the tier to carry a block price (flatAmount).');
    }
}
