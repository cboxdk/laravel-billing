<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\CohortRetention;
use InvalidArgumentException;

/**
 * One subscription's MRR observed across an ordered series of periods — the input to
 * a {@see CohortRetention} matrix. `$mrrByPeriod` is aligned
 * positionally to the period labels handed to the calculator; a period in which the
 * subscription contributes nothing is {@see Money::zero()}. `$cohort` is the label of
 * the period the subscription started in (its age-0 bucket).
 *
 * Every amount is in the same currency — a subscription is billed in one currency,
 * and cohort MRR totals never mix currencies.
 */
readonly class SubscriptionPeriodMrr
{
    /**
     * @param  list<Money>  $mrrByPeriod
     */
    public function __construct(
        public string $subscriptionId,
        public string $cohort,
        public array $mrrByPeriod,
    ) {
        $currency = null;

        foreach ($mrrByPeriod as $amount) {
            if ($currency === null) {
                $currency = $amount->currency();

                continue;
            }

            if ($amount->currency() !== $currency) {
                throw new InvalidArgumentException(
                    "Subscription {$subscriptionId} mixes currencies ({$currency} and {$amount->currency()})."
                );
            }
        }
    }

    /** The currency of this subscription's MRR series, or null when the series is empty. */
    public function currency(): ?string
    {
        if ($this->mrrByPeriod === []) {
            return null;
        }

        return $this->mrrByPeriod[0]->currency();
    }
}
