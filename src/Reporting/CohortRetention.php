<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\ValueObjects\CohortCell;
use Cbox\Billing\Reporting\ValueObjects\CohortMatrix;
use Cbox\Billing\Reporting\ValueObjects\CohortRow;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionPeriodMrr;
use InvalidArgumentException;

/**
 * Builds a cohort × age retention matrix: subscriptions are grouped by the period
 * they started in (their cohort), and for each cohort the calculator reports the
 * retained subscription count and retained MRR at that period and every later one.
 *
 * A subscription is "retained" at a period when its MRR there is positive. Each
 * cohort is single-currency (a cohort MRR total never mixes currencies); cohorts and
 * their cells are emitted in deterministic order (cohorts by label, cells by period).
 * Pure and stateless.
 */
readonly class CohortRetention
{
    /**
     * @param  list<string>  $periods  ordered period labels the matrix is defined over
     * @param  iterable<SubscriptionPeriodMrr>  $subscriptions
     */
    public function matrix(array $periods, iterable $subscriptions): CohortMatrix
    {
        $periodCount = count($periods);
        $index = array_flip($periods);

        /** @var array<string, list<SubscriptionPeriodMrr>> $cohorts */
        $cohorts = [];

        foreach ($subscriptions as $subscription) {
            if (count($subscription->mrrByPeriod) !== $periodCount) {
                throw new InvalidArgumentException(
                    "Subscription {$subscription->subscriptionId} has ".count($subscription->mrrByPeriod)
                    ." MRR points but the matrix defines {$periodCount} periods."
                );
            }

            if (! isset($index[$subscription->cohort])) {
                throw new InvalidArgumentException(
                    "Cohort '{$subscription->cohort}' is not one of the defined periods."
                );
            }

            $cohorts[$subscription->cohort][] = $subscription;
        }

        ksort($cohorts);

        $rows = [];

        foreach ($cohorts as $cohort => $members) {
            $rows[] = $this->row($cohort, $index[$cohort], $periodCount, $members);
        }

        return new CohortMatrix($periods, $rows);
    }

    /**
     * @param  list<SubscriptionPeriodMrr>  $members
     */
    private function row(string $cohort, int $startIndex, int $periodCount, array $members): CohortRow
    {
        $currency = $this->currencyFor($cohort, $members);

        $cells = [];
        $initialCount = 0;
        $initialMrr = Money::zero($currency);

        for ($periodIndex = $startIndex; $periodIndex < $periodCount; $periodIndex++) {
            $retainedCount = 0;
            $retainedMrr = Money::zero($currency);

            foreach ($members as $member) {
                $amount = $member->mrrByPeriod[$periodIndex];

                if ($amount->isPositive()) {
                    $retainedCount++;
                    $retainedMrr = $retainedMrr->plus($amount);
                }
            }

            if ($periodIndex === $startIndex) {
                $initialCount = $retainedCount;
                $initialMrr = $retainedMrr;
            }

            $cells[] = new CohortCell($periodIndex, $periodIndex - $startIndex, $retainedCount, $retainedMrr);
        }

        return new CohortRow($cohort, $currency, $initialCount, $initialMrr, $cells);
    }

    /**
     * The single currency shared by a cohort's members. Members with an empty MRR
     * series carry no currency and are skipped; a cohort that yields no currency at
     * all is rejected rather than totalled in a guessed one.
     *
     * @param  list<SubscriptionPeriodMrr>  $members
     */
    private function currencyFor(string $cohort, array $members): string
    {
        $currency = null;

        foreach ($members as $member) {
            $memberCurrency = $member->currency();

            if ($memberCurrency === null) {
                continue;
            }

            if ($currency === null) {
                $currency = $memberCurrency;

                continue;
            }

            if ($memberCurrency !== $currency) {
                throw new InvalidArgumentException(
                    "Cohort '{$cohort}' mixes currencies ({$currency} and {$memberCurrency}); a cohort MRR total is single-currency."
                );
            }
        }

        if ($currency === null) {
            throw new InvalidArgumentException("Cohort '{$cohort}' has no currency to total in.");
        }

        return $currency;
    }
}
