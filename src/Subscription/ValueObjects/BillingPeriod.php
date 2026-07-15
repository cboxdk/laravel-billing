<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use DateTimeImmutable;

/**
 * A billing period [start, end). Proration is day-based: the remaining days at a
 * mid-period date over the total days give the fraction of a price change that is
 * charged now.
 */
readonly class BillingPeriod
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}

    public function totalDays(): int
    {
        return (int) $this->start->diff($this->end)->days;
    }

    public function remainingDays(DateTimeImmutable $at): int
    {
        if ($at <= $this->start) {
            return $this->totalDays();
        }

        if ($at >= $this->end) {
            return 0;
        }

        return (int) $at->diff($this->end)->days;
    }
}
