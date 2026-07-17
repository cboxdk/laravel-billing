<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * One cohort's retention over time: the subscriptions that started in `$cohort` and
 * what remained of them at each subsequent period. `$cells` runs from the cohort's
 * own start period (age 0) forward, ordered by period. `$initialCount`/`$initialMrr`
 * are the age-0 baseline the later cells are read against.
 */
readonly class CohortRow
{
    /**
     * @param  list<CohortCell>  $cells
     */
    public function __construct(
        public string $cohort,
        public string $currency,
        public int $initialCount,
        public Money $initialMrr,
        public array $cells,
    ) {}

    public function cellAtAge(int $age): ?CohortCell
    {
        foreach ($this->cells as $cell) {
            if ($cell->age === $age) {
                return $cell;
            }
        }

        return null;
    }
}
