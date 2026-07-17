<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * One step of a {@see RampSchedule}: from `$fromPeriodIndex` (a 0-based billing-period
 * index into the subscription's term) onward, the effective recurring amount is
 * `$amount`, until a later step with a higher `fromPeriodIndex` takes over.
 *
 * Amounts are held as {@see Money} (integer minor units) so a ramp is exact — the step
 * carries the price directly rather than a reference, keeping the schedule
 * self-contained and gateway-agnostic.
 */
readonly class RampStep
{
    public function __construct(
        public int $fromPeriodIndex,
        public Money $amount,
    ) {}
}
