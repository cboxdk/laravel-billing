<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\ValueObjects\MrrWaterfall;
use Cbox\Billing\Reporting\ValueObjects\RetentionRates;
use Cbox\Billing\Reporting\ValueObjects\RetentionRatio;
use InvalidArgumentException;

/**
 * Net and gross revenue retention for a starting cohort, computed from exact
 * minor-unit sums (no float):
 *
 *   NRR = (start + expansion − contraction − churn) / start
 *   GRR = (start − contraction − churn) / start
 *
 * Both are measured against the MRR the cohort started with, so new logos and
 * reactivations — which have no starting MRR in the cohort — are excluded by
 * construction. The result is a {@see RetentionRates} holding two
 * {@see RetentionRatio} fractions; read basis points or the raw numerator/denominator
 * to avoid float drift. Pure and stateless.
 */
readonly class RetentionCalculator
{
    /**
     * Retention from a cohort's raw movement magnitudes. `$expansion`, `$contraction`
     * and `$churn` are all positive magnitudes in the same currency as `$start`.
     */
    public function forCohort(Money $start, Money $expansion, Money $contraction, Money $churn): RetentionRates
    {
        $currency = $start->currency();

        foreach (['expansion' => $expansion, 'contraction' => $contraction, 'churn' => $churn] as $name => $amount) {
            if ($amount->currency() !== $currency) {
                throw new InvalidArgumentException(
                    "Retention inputs must share a currency; {$name} is {$amount->currency()}, expected {$currency}."
                );
            }
        }

        $startMinor = $start->minor();

        $nrrNumerator = $startMinor + $expansion->minor() - $contraction->minor() - $churn->minor();
        $grrNumerator = $startMinor - $contraction->minor() - $churn->minor();

        return new RetentionRates(
            $currency,
            new RetentionRatio($nrrNumerator, $startMinor),
            new RetentionRatio($grrNumerator, $startMinor),
        );
    }

    /**
     * Retention derived from an {@see MrrWaterfall} — uses the waterfall's start,
     * expansion, contraction and churn (its new/reactivation are ignored, as retention
     * excludes them).
     */
    public function fromWaterfall(MrrWaterfall $waterfall): RetentionRates
    {
        return $this->forCohort(
            $waterfall->startMrr,
            $waterfall->expansion,
            $waterfall->contraction,
            $waterfall->churn,
        );
    }
}
