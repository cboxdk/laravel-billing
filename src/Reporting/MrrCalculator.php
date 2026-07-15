<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\ValueObjects\MrrLine;
use Cbox\Billing\Reporting\ValueObjects\RevenueReport;

/**
 * Sums normalized monthly recurring amounts into MRR (and ARR = MRR × 12) per
 * currency. The caller supplies each active subscription's monthly-equivalent
 * amount (annual plans divided to a monthly figure upstream).
 */
readonly class MrrCalculator
{
    /**
     * @param  iterable<Money>  $monthlyAmounts
     */
    public function summarize(iterable $monthlyAmounts): RevenueReport
    {
        /** @var array<string, MrrLine> $lines */
        $lines = [];

        foreach ($monthlyAmounts as $amount) {
            $currency = $amount->currency();

            if (isset($lines[$currency])) {
                $mrr = $lines[$currency]->mrr->plus($amount);
                $lines[$currency] = new MrrLine($currency, $mrr, $mrr->multipliedBy(12), $lines[$currency]->subscriptions + 1);
            } else {
                $lines[$currency] = new MrrLine($currency, $amount, $amount->multipliedBy(12), 1);
            }
        }

        return new RevenueReport(array_values($lines));
    }
}
