<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * One cell of a {@see CohortMatrix}: how much of a cohort survived at a given period.
 * `$periodIndex` is the absolute index into the matrix's period list; `$age` is the
 * offset from the cohort's own start period (0 = the cohort's first period).
 */
readonly class CohortCell
{
    public function __construct(
        public int $periodIndex,
        public int $age,
        public int $retainedCount,
        public Money $retainedMrr,
    ) {}
}
