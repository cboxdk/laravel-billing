<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Pricing;

use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Exceptions\MalformedTierSet;
use Cbox\Billing\Catalog\ValueObjects\PriceTier;
use Cbox\Billing\Money\Money;

/**
 * Prices a quantity under a tiered {@see PricingModel} against an ordered set of
 * {@see PriceTier}s. Every model is computed in integer MINOR UNITS — the charge is
 * only ever a sum of `unitAmount × wholeUnits` and `flatAmount` terms, never a
 * division, so no minor unit is ever rounded away or duplicated (remainder-safe by
 * construction).
 *
 * Deny-by-default: an empty, mis-ordered, negatively-priced, or gap-having tier set,
 * a package without a positive size/block price, or a quantity that no tier covers
 * raises {@see MalformedTierSet} rather than silently returning zero or a wrong
 * amount.
 */
class TierCalculator
{
    /**
     * The total charge for `$quantity` units under `$model`.
     *
     * @param  list<PriceTier>  $tiers  ordered brackets; only the last may be unbounded (`upTo = null`)
     * @param  int  $quantity  the (aggregated) billable quantity; may be zero (→ zero charge)
     * @param  ?int  $packageSize  block size, required and used only by {@see PricingModel::Package}
     */
    public function price(PricingModel $model, array $tiers, int $quantity, ?int $packageSize = null): Money
    {
        if (! $model->isTiered()) {
            throw MalformedTierSet::notTiered($model);
        }

        if ($quantity < 0) {
            throw MalformedTierSet::negativeQuantity($quantity);
        }

        $currency = $this->assertValidTierSet($model, $tiers);

        if ($quantity === 0) {
            // No usage, no charge — never a phantom base fee for zero units.
            return Money::zero($currency);
        }

        return match ($model) {
            PricingModel::Graduated => $this->graduated($tiers, $quantity, $currency),
            PricingModel::Volume => $this->volume($tiers, $quantity, $currency),
            PricingModel::Package => $this->package($tiers, $quantity, $packageSize, $currency),
            PricingModel::Stairstep => $this->stairstep($tiers, $quantity, $currency),
            PricingModel::Flat, PricingModel::PerUnit => throw MalformedTierSet::notTiered($model),
        };
    }

    /**
     * Each slice of the quantity is priced at the rate of the tier it falls in; the
     * charge is the sum across tiers, plus each reached tier's flat fee.
     *
     * @param  list<PriceTier>  $tiers
     */
    private function graduated(array $tiers, int $quantity, string $currency): Money
    {
        $total = Money::zero($currency);
        $lowerExclusive = 0;

        foreach ($tiers as $tier) {
            if ($quantity <= $lowerExclusive) {
                break;
            }

            $upper = $tier->upTo ?? $quantity;
            $unitsInTier = min($quantity, $upper) - $lowerExclusive;

            if ($unitsInTier > 0) {
                $total = $total->plus($tier->unitAmount->multipliedBy($unitsInTier));

                if ($tier->flatAmount !== null) {
                    $total = $total->plus($tier->flatAmount);
                }
            }

            $lowerExclusive = $upper;
        }

        return $total;
    }

    /**
     * ALL units are priced at the single tier the total quantity lands in.
     *
     * @param  list<PriceTier>  $tiers
     */
    private function volume(array $tiers, int $quantity, string $currency): Money
    {
        $tier = $this->tierFor($tiers, $quantity);
        $total = $tier->unitAmount->multipliedBy($quantity);

        return $tier->flatAmount !== null ? $total->plus($tier->flatAmount) : $total;
    }

    /**
     * `ceil(quantity / packageSize)` whole blocks, each charged the block's flat
     * amount (the first tier's `flatAmount`).
     *
     * @param  list<PriceTier>  $tiers
     */
    private function package(array $tiers, int $quantity, ?int $packageSize, string $currency): Money
    {
        if ($packageSize === null || $packageSize <= 0) {
            throw MalformedTierSet::packageSize($packageSize);
        }

        $blockPrice = $tiers[0]->flatAmount;

        if ($blockPrice === null) {
            throw MalformedTierSet::missingBlockPrice();
        }

        $blocks = intdiv($quantity + $packageSize - 1, $packageSize);

        return $blockPrice->multipliedBy($blocks);
    }

    /**
     * A single flat amount for the whole bracket the quantity lands in.
     *
     * @param  list<PriceTier>  $tiers
     */
    private function stairstep(array $tiers, int $quantity, string $currency): Money
    {
        $tier = $this->tierFor($tiers, $quantity);

        return $tier->flatAmount ?? Money::zero($currency);
    }

    /**
     * The first tier whose (inclusive) upper bound contains the quantity.
     *
     * @param  list<PriceTier>  $tiers
     */
    private function tierFor(array $tiers, int $quantity): PriceTier
    {
        foreach ($tiers as $tier) {
            if ($tier->contains($quantity)) {
                return $tier;
            }
        }

        throw MalformedTierSet::uncovered($quantity);
    }

    /**
     * Validate ordering, positivity, and single-currency; return the shared currency.
     *
     * @param  list<PriceTier>  $tiers
     */
    private function assertValidTierSet(PricingModel $model, array $tiers): string
    {
        if ($tiers === []) {
            throw MalformedTierSet::empty($model);
        }

        $currency = $tiers[0]->unitAmount->currency();
        $lastIndex = count($tiers) - 1;
        $previousBound = 0;

        foreach ($tiers as $index => $tier) {
            if ($tier->unitAmount->currency() !== $currency
                || ($tier->flatAmount !== null && $tier->flatAmount->currency() !== $currency)
            ) {
                throw MalformedTierSet::currencyMismatch();
            }

            if ($tier->unitAmount->isNegative()
                || ($tier->flatAmount !== null && $tier->flatAmount->isNegative())
            ) {
                throw MalformedTierSet::negativeAmount();
            }

            if ($tier->upTo === null) {
                // Only the final tier may be unbounded.
                if ($index !== $lastIndex) {
                    throw MalformedTierSet::unorderedBounds();
                }

                continue;
            }

            if ($tier->upTo <= $previousBound) {
                throw MalformedTierSet::unorderedBounds();
            }

            $previousBound = $tier->upTo;
        }

        return $currency;
    }
}
