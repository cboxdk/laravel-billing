<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

/**
 * Net and gross revenue retention for one cohort (or the overall book) in one
 * currency.
 *
 *   NRR = (start + expansion − contraction − churn) / start
 *   GRR = (start − contraction − churn) / start
 *
 * Both exclude new logos and reactivations — retention is measured on the MRR the
 * cohort started with. GRR ≤ NRR always, since GRR omits the expansion term.
 */
readonly class RetentionRates
{
    public function __construct(
        public string $currency,
        public RetentionRatio $nrr,
        public RetentionRatio $grr,
    ) {}
}
