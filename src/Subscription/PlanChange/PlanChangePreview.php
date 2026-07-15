<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\ValueObjects\Quote;
use DateTimeImmutable;

/**
 * The full consequence of a plan change, for a confirm step. An upgrade charges a
 * prorated difference now (taxed, in `dueNowQuote`) and takes effect immediately; a
 * downgrade is scheduled for the period end with nothing due now (`dueNowQuote` is
 * null). Either way `newRecurring` is the price from the next full period.
 */
readonly class PlanChangePreview
{
    public function __construct(
        public bool $isUpgrade,
        public Money $proratedNet,
        public ?Quote $dueNowQuote,
        public Money $newRecurring,
        public DateTimeImmutable $effectiveAt,
    ) {}
}
