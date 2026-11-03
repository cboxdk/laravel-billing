<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

use Cbox\Billing\Catalog\Enums\PercentageTierMode;
use Cbox\Billing\Catalog\Exceptions\MalformedPercentagePrice;
use Cbox\Billing\Catalog\Pricing\PercentageCalculator;
use Cbox\Billing\Money\Money;

/**
 * The terms of a percentage price: a rate of a reported value, such as 1.5% of transaction volume.
 *
 *  - `$tiers`     the rate by volume. One unbounded tier is a single rate; more are read under
 *                 `$tierMode` ({@see PercentageTierMode}).
 *  - `$fixedFee`  an optional amount added per fee unit (an event, or a unit the writer counts).
 *  - `$minimum`   an optional least charge for a period that had any volume or fee unit.
 *  - `$maximum`   an optional cap on a period's charge.
 *
 * The volume is in the minor units of `$currency`, and so are the tier bounds. Checked when it is
 * built: a malformed set raises {@see MalformedPercentagePrice}. Charged by {@see PercentageCalculator}.
 */
final readonly class PercentagePrice
{
    /** @param  list<PercentageTier>  $tiers */
    public function __construct(
        public string $currency,
        public array $tiers,
        public PercentageTierMode $tierMode = PercentageTierMode::Graduated,
        public ?Money $fixedFee = null,
        public ?Money $minimum = null,
        public ?Money $maximum = null,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw MalformedPercentagePrice::currency($currency);
        }

        if ($tiers === []) {
            throw MalformedPercentagePrice::noTiers();
        }

        $previous = 0;
        $last = count($tiers) - 1;

        foreach ($tiers as $index => $tier) {
            $misplaced = $tier->upTo === null
                ? $index !== $last
                : $index === $last || $tier->upTo <= $previous;

            if ($misplaced) {
                throw MalformedPercentagePrice::unorderedBounds();
            }

            $previous = $tier->upTo ?? $previous;
        }

        foreach (['fixed fee' => $fixedFee, 'minimum' => $minimum, 'maximum' => $maximum] as $what => $amount) {
            if ($amount === null) {
                continue;
            }

            if ($amount->currency() !== $currency) {
                throw MalformedPercentagePrice::currencyMismatch($currency, $amount->currency());
            }

            if ($amount->isNegative()) {
                throw MalformedPercentagePrice::negativeAmount($what);
            }
        }

        if ($minimum !== null && $maximum !== null && $minimum->compareTo($maximum) > 0) {
            throw MalformedPercentagePrice::minimumAboveMaximum();
        }
    }

    /** A single rate with no tiers, fee or bounds. */
    public static function flat(string $currency, PercentageRate $rate): self
    {
        return new self($currency, [new PercentageTier(null, $rate)]);
    }
}
