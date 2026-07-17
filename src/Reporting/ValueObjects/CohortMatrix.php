<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

/**
 * A cohort × age retention matrix: one {@see CohortRow} per start-period cohort, each
 * carrying the retained count and retained MRR at every period from its start onward.
 * `$periods` is the ordered list of period labels the matrix is defined over; rows are
 * ordered by cohort label for determinism.
 */
readonly class CohortMatrix
{
    /**
     * @param  list<string>  $periods
     * @param  list<CohortRow>  $rows
     */
    public function __construct(
        public array $periods,
        public array $rows,
    ) {}

    public function rowFor(string $cohort): ?CohortRow
    {
        foreach ($this->rows as $row) {
            if ($row->cohort === $cohort) {
                return $row;
            }
        }

        return null;
    }
}
