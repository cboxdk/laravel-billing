<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Pricing;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Cbox\Billing\Catalog\Enums\PercentageTierMode;
use Cbox\Billing\Catalog\Exceptions\MalformedPercentagePrice;
use Cbox\Billing\Catalog\ValueObjects\PercentageCharge;
use Cbox\Billing\Catalog\ValueObjects\PercentagePrice;
use Cbox\Billing\Catalog\ValueObjects\PercentageTier;
use Cbox\Billing\Money\Money;

/**
 * Charges a {@see PercentagePrice} for one period's volume.
 *
 * Every term is summed exactly, each slice as `volume × partsPerMillion` and the fees as
 * `fixedFee × feeUnits`, and the total is rounded ONCE, half up, to a whole minor unit. Rounding
 * per event or per slice would let many small amounts each round to zero; rounding the sum cannot.
 * The minimum and maximum then bound the rounded total. Both are whole amounts, so nothing is
 * rounded again.
 *
 * A period with no volume and no fee units is charged nothing, minimum included.
 */
class PercentageCalculator
{
    /**
     * @param  int  $volume  the reported value for the period, in the price currency's minor units
     * @param  int  $feeUnits  how many times the fixed fee applies (events, or units the writer counts)
     */
    public function charge(PercentagePrice $price, int $volume, int $feeUnits = 0): PercentageCharge
    {
        if ($volume < 0) {
            throw MalformedPercentagePrice::negativeQuantity('volume', $volume);
        }

        if ($feeUnits < 0) {
            throw MalformedPercentagePrice::negativeQuantity('number of fee units', $feeUnits);
        }

        $zero = Money::zero($price->currency);

        if ($volume === 0 && $feeUnits === 0) {
            return new PercentageCharge(0, 0, $zero, $zero);
        }

        $fees = $price->fixedFee?->multipliedBy($feeUnits) ?? $zero;

        // Millionths of a minor unit, exact: a bigint volume times 10^6 runs past 64 bits.
        $millionths = match ($price->tierMode) {
            PercentageTierMode::Graduated => $this->graduated($price->tiers, $volume),
            PercentageTierMode::Volume => BigInteger::of($volume)->multipliedBy($this->tierFor($price->tiers, $volume)->rate->partsPerMillion),
        };

        $minor = $millionths->toBigDecimal()
            ->dividedBy(1_000_000, 0, RoundingMode::HalfUp)
            ->toBigInteger()
            ->plus($fees->minor());

        $total = Money::ofMinor($minor->isGreaterThan(PHP_INT_MAX) ? PHP_INT_MAX : $minor->toInt(), $price->currency);
        $bound = null;

        if ($price->minimum !== null && $total->compareTo($price->minimum) < 0) {
            [$total, $bound] = [$price->minimum, 'minimum'];
        } elseif ($price->maximum !== null && $total->compareTo($price->maximum) > 0) {
            [$total, $bound] = [$price->maximum, 'maximum'];
        }

        return new PercentageCharge($volume, $feeUnits, $fees, $total, $bound);
    }

    /** @param  list<PercentageTier>  $tiers */
    private function graduated(array $tiers, int $volume): BigInteger
    {
        $sum = BigInteger::zero();
        $lower = 0;

        foreach ($tiers as $tier) {
            if ($volume <= $lower) {
                break;
            }

            $upper = $tier->upTo ?? $volume;
            $slice = min($volume, $upper) - $lower;

            if ($slice > 0) {
                $sum = $sum->plus(BigInteger::of($slice)->multipliedBy($tier->rate->partsPerMillion));
            }

            $lower = $upper;
        }

        return $sum;
    }

    /** @param  list<PercentageTier>  $tiers */
    private function tierFor(array $tiers, int $volume): PercentageTier
    {
        foreach ($tiers as $tier) {
            if ($tier->contains($volume)) {
                return $tier;
            }
        }

        // The terms always end in an unbounded tier, which contains every volume.
        return $tiers[count($tiers) - 1];
    }
}
