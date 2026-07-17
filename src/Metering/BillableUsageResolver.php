<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering;

use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Pricing\TierCalculator;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Cbox\Billing\Money\Money;

/**
 * Turns a meter's raw usage over a period into what it should cost, in two composable
 * steps:
 *
 *  1. `quantity()` — collapse the {@see EventLog} events for `(org, meter)` in a
 *     window into ONE billable quantity using the {@see MeterPolicy}'s configured
 *     {@see Aggregation} (count / sum / max / unique-count
 *     / latest / weighted-sum).
 *  2. `charge()` — feed that quantity to a {@see Price} (which prices it under its
 *     {@see PricingModel} — flat, per-unit, or a tiered
 *     model via {@see TierCalculator}), yielding the {@see Money} to bill.
 *
 * This is the usage-events → aggregate → tiered-price → Money pipeline, kept as a thin
 * adapter over the two contracts so a host can swap either side.
 */
readonly class BillableUsageResolver
{
    public function __construct(private EventLog $log) {}

    /**
     * The billable quantity for `(org, meter)` in the inclusive millisecond-epoch
     * window, aggregated per the policy's configured aggregation.
     */
    public function quantity(string $org, string $meter, int $fromMs, int $toMs, MeterPolicy $policy): int
    {
        return $this->log->aggregate($org, $meter, $fromMs, $toMs, $policy->aggregation);
    }

    /**
     * The amount to charge: the aggregated billable quantity priced by `$price`.
     */
    public function charge(string $org, string $meter, int $fromMs, int $toMs, MeterPolicy $policy, Price $price): Money
    {
        return $price->amountFor($this->quantity($org, $meter, $fromMs, $toMs, $policy));
    }
}
