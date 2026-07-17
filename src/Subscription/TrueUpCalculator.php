<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\ValueObjects\MinimumCommitment;

/**
 * The pure minimum-commitment true-up: given a period's committed floor and the amount
 * actually charged for that period (recurring + metered), it returns the shortfall to
 * bill — `max(minimum − actual, 0)`. The invoice/renewal path calls this at period
 * close and, when the result is positive, appends it as a true-up line.
 *
 * Everything is in integer minor units, so the result is exact and remainder-safe;
 * a currency mismatch between the floor and the actual amount is refused by
 * {@see Money::minus()}.
 *
 * @see MinimumCommitment
 */
class TrueUpCalculator
{
    /** The shortfall to bill: `max($minimum − $actual, 0)`, floored at zero. */
    public static function shortfall(Money $minimum, Money $actual): Money
    {
        $difference = $minimum->minus($actual);

        return $difference->isPositive() ? $difference : Money::zero($minimum->currency());
    }
}
