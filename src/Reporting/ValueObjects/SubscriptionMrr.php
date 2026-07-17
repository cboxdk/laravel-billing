<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\MrrCalculator;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;

/**
 * A subscription's monthly-recurring amount paired with its lifecycle status — the
 * input to {@see MrrCalculator::summarizeSubscriptions()},
 * which applies the state→MRR policy so callers do not have to pre-filter by status.
 * The monthly amount is the subscription's normalised monthly-equivalent recurring
 * charge (annual plans divided upstream); metered usage is not MRR.
 */
readonly class SubscriptionMrr
{
    public function __construct(
        public SubscriptionStatus $status,
        public Money $monthlyAmount,
    ) {}
}
