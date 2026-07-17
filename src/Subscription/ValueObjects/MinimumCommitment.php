<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\TrueUpCalculator;

/**
 * A minimum spend the org commits to per billing period. At period close, if the
 * period's actual charged amount (recurring + metered) falls below this floor, the
 * shortfall is billed as a true-up line so the commitment is met.
 *
 * The floor is held as {@see Money} (integer minor units); the shortfall is computed by
 * the pure {@see TrueUpCalculator}.
 */
readonly class MinimumCommitment
{
    public function __construct(
        public Money $minimum,
    ) {}

    /**
     * The true-up for a period whose actual charged amount was `$actual`:
     * `max(minimum − actual, 0)`, in the commitment's currency. Zero when the period
     * already met or exceeded the floor.
     */
    public function trueUp(Money $actual): Money
    {
        return TrueUpCalculator::shortfall($this->minimum, $actual);
    }
}
