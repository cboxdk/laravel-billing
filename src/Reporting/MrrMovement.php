<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\ValueObjects\MrrMovementReport;
use Cbox\Billing\Reporting\ValueObjects\MrrWaterfall;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionMovement;

/**
 * Decomposes the MRR change between two periods into the five standard movement
 * components — new, expansion, contraction, churn (lost), reactivation — per
 * currency, so that for every currency:
 *
 *   startMrr + new + expansion − contraction − churn + reactivation = endMrr
 *
 * Each supplied {@see SubscriptionMovement} carries one subscription's start- and
 * end-period MRR; the subscription is classified from the two values (and, for a
 * zero→positive transition, its `returning` flag):
 *
 *  - start = 0, end > 0, not returning → **new** (the full end amount)
 *  - start = 0, end > 0, returning     → **reactivation** (the full end amount)
 *  - start > 0, end = 0                 → **churn** (the full start amount, as a magnitude)
 *  - start > 0, end > start             → **expansion** (the increase)
 *  - start > 0, end < start (end > 0)   → **contraction** (the decrease, as a magnitude)
 *  - start > 0, end = start             → no movement
 *
 * Everything is exact minor-unit arithmetic; contraction and churn are accumulated
 * as positive magnitudes. Pure and stateless.
 */
readonly class MrrMovement
{
    /**
     * @param  iterable<SubscriptionMovement>  $movements
     */
    public function waterfall(iterable $movements): MrrMovementReport
    {
        /** @var array<string, array{start: Money, end: Money, new: Money, expansion: Money, contraction: Money, churn: Money, reactivation: Money}> $acc */
        $acc = [];

        foreach ($movements as $movement) {
            $currency = $movement->currency();

            if (! isset($acc[$currency])) {
                $zero = Money::zero($currency);
                $acc[$currency] = [
                    'start' => $zero,
                    'end' => $zero,
                    'new' => $zero,
                    'expansion' => $zero,
                    'contraction' => $zero,
                    'churn' => $zero,
                    'reactivation' => $zero,
                ];
            }

            $start = $movement->startMrr;
            $end = $movement->endMrr;

            $acc[$currency]['start'] = $acc[$currency]['start']->plus($start);
            $acc[$currency]['end'] = $acc[$currency]['end']->plus($end);

            if ($start->isZero() && $end->isPositive()) {
                $bucket = $movement->returning ? 'reactivation' : 'new';
                $acc[$currency][$bucket] = $acc[$currency][$bucket]->plus($end);

                continue;
            }

            if ($start->isPositive() && $end->isZero()) {
                $acc[$currency]['churn'] = $acc[$currency]['churn']->plus($start);

                continue;
            }

            if ($start->isPositive() && $end->isPositive()) {
                $delta = $end->minus($start);

                if ($delta->isPositive()) {
                    $acc[$currency]['expansion'] = $acc[$currency]['expansion']->plus($delta);
                } elseif ($delta->isNegative()) {
                    $acc[$currency]['contraction'] = $acc[$currency]['contraction']->plus($delta->negated());
                }
            }
        }

        ksort($acc);

        $waterfalls = [];

        foreach ($acc as $currency => $parts) {
            $waterfalls[] = new MrrWaterfall(
                $currency,
                $parts['start'],
                $parts['end'],
                $parts['new'],
                $parts['expansion'],
                $parts['contraction'],
                $parts['churn'],
                $parts['reactivation'],
            );
        }

        return new MrrMovementReport($waterfalls);
    }
}
