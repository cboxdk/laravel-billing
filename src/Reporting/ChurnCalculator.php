<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting;

/**
 * Customer churn over a period: the fraction of the customers present at the start
 * that were lost. Returns 0.0 when there were none to start with.
 */
readonly class ChurnCalculator
{
    public function rate(int $atStart, int $churned): float
    {
        if ($atStart <= 0) {
            return 0.0;
        }

        return $churned / $atStart;
    }
}
