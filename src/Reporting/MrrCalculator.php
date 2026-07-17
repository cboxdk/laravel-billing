<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\ValueObjects\MrrLine;
use Cbox\Billing\Reporting\ValueObjects\RevenueReport;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionMrr;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;

/**
 * Sums normalized monthly recurring amounts into MRR (and ARR = MRR × 12) per
 * currency. The caller supplies each active subscription's monthly-equivalent
 * amount (annual plans divided to a monthly figure upstream).
 *
 * State→MRR policy (see {@see contributes()}): a subscription only counts toward MRR
 * while it is actually being charged for its plan. `Active`, `PastDue` and
 * `NonRenewing` contribute their full recurring amount — a past-due subscription is
 * still serving under dunning, and a non-renewing one still bills its final period.
 * `Trialing` contributes 0 until it converts (a trial is not yet revenue), `Paused`
 * contributes 0 (billing is suspended), and `Canceled` contributes 0 (terminal, on
 * no plan). Note this is stricter than {@see SubscriptionStatus::isServing()}, which
 * also grants entitlements while `Trialing`: a trial serves the plan but is not MRR.
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

    /**
     * Summarize MRR from status-tagged subscriptions, applying the state→MRR policy so
     * callers do not pre-filter: non-contributing states ({@see contributes()}) add
     * nothing and are not counted; contributing states add their monthly amount and
     * increment the subscription count. Per currency, exactly like {@see summarize()}.
     *
     * @param  iterable<SubscriptionMrr>  $subscriptions
     */
    public function summarizeSubscriptions(iterable $subscriptions): RevenueReport
    {
        /** @var array<string, MrrLine> $lines */
        $lines = [];

        foreach ($subscriptions as $subscription) {
            if (! $this->contributes($subscription->status)) {
                continue;
            }

            $amount = $subscription->monthlyAmount;
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

    /**
     * Whether a subscription in the given lifecycle state contributes to MRR. See the
     * class docblock for the rationale behind each state.
     */
    public function contributes(SubscriptionStatus $status): bool
    {
        return match ($status) {
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
            SubscriptionStatus::NonRenewing => true,
            SubscriptionStatus::Trialing,
            SubscriptionStatus::Paused,
            SubscriptionStatus::Canceled => false,
        };
    }
}
