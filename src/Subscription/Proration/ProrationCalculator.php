<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Proration;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use DateTimeImmutable;

/**
 * Prorates a price change over the remaining part of a billing period: the
 * per-period difference between the new and current price, scaled by the fraction
 * of the period still to run. Positive for an upgrade (charge the difference now),
 * negative for a downgrade.
 */
readonly class ProrationCalculator
{
    public function prorate(Money $current, Money $new, BillingPeriod $period, DateTimeImmutable $at): Money
    {
        $delta = $new->minus($current);
        $total = $period->totalDays();

        if ($total <= 0) {
            return Money::zero($current->currency());
        }

        return $delta->proratedBy($period->remainingDays($at), $total);
    }
}
