<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Proration;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\AnchorMode;
use Cbox\Billing\Subscription\Enums\GatewayRounding;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use DateTimeImmutable;

/**
 * The one function that prices a plan change. The previewer and the charger both call
 * {@see compute()}, so identical inputs yield an identical {@see Proration} by
 * construction — a preview cannot drift from the charge because they are the same
 * computation, not two that are kept in step.
 *
 * Every line is rounded to whole minor units with the request's gateway rounding
 * *before* anything is summed; the net is the sum of already-rounded lines, never a
 * rounded total. The calculator is explicit about the anchor, may net a credit on a
 * reset, defers kept-anchor downgrades, charges a full fresh period from
 * pay-as-you-go, clamps an instant that precedes the period, and treats a
 * zero-length period as zero remaining rather than dividing by it.
 */
readonly class ProrationCalculator
{
    /**
     * Price a plan change into its independently-rounded lines. This is the single
     * source of truth shared by preview and charge.
     */
    public function compute(ProrationRequest $request): Proration
    {
        $currency = $request->newPrice->currency();

        if ($request->isDeferredDowngrade()) {
            return new Proration(
                lines: [],
                net: Money::zero($currency),
                deferred: true,
                effectiveAt: $request->period->end,
                anchor: $request->anchor,
                rounding: $request->rounding,
                currency: $currency,
            );
        }

        $lines = $request->anchor === AnchorMode::Reset
            ? $this->resetLines($request, $currency)
            : $this->keepLines($request, $currency);

        $net = Money::zero($currency);
        foreach ($lines as $line) {
            $net = $net->plus($line->amount);
        }

        return new Proration(
            lines: $lines,
            net: $net,
            deferred: false,
            effectiveAt: $request->at,
            anchor: $request->anchor,
            rounding: $request->rounding,
            currency: $currency,
        );
    }

    /**
     * Keep the anchor: charge only the prorated price delta over the remaining period.
     * From pay-as-you-go (no current price) this prorates the new price as a mid-cycle
     * join. Downgrades never reach here — they defer.
     *
     * @return list<ProrationLine>
     */
    private function keepLines(ProrationRequest $request, string $currency): array
    {
        $current = $request->currentPrice ?? Money::zero($currency);
        $delta = $request->newPrice->minus($current);

        $line = $this->proratedLine('Prorated plan change', $delta, $request->period, $request->at, $request->rounding);

        return [$line];
    }

    /**
     * Reset the anchor: charge a full fresh period at the new price and, when there is a
     * committed base, credit its unused (remaining) part. Each line is rounded on its
     * own, so a large unused base can net past the fresh price into an overall credit.
     * From pay-as-you-go there is no base, so nothing is credited.
     *
     * @return list<ProrationLine>
     */
    private function resetLines(ProrationRequest $request, string $currency): array
    {
        $lines = [new ProrationLine('Fresh period', $request->newPrice)];

        if ($request->currentPrice !== null) {
            $unusedBase = $this->proratedLine(
                'Unused base credit',
                $request->currentPrice->negated(),
                $request->period,
                $request->at,
                $request->rounding,
            );

            $lines[] = $unusedBase;
        }

        return $lines;
    }

    /**
     * Prorate a full-period amount over the days still to run and round the result to
     * whole minor units with the gateway's mode. An instant at or before the period
     * start counts the whole period; one at or after the end counts nothing; a
     * zero-length period yields zero without dividing by it.
     */
    private function proratedLine(string $description, Money $amount, BillingPeriod $period, DateTimeImmutable $at, GatewayRounding $rounding): ProrationLine
    {
        $total = $period->totalDays();
        $remaining = $period->remainingDays($at);

        if ($total <= 0) {
            return new ProrationLine($description, Money::zero($amount->currency()));
        }

        $scaled = Money::fromBrick(
            $amount->toBrick()->multipliedBy($remaining)->dividedBy($total, $rounding->mode()),
        );

        return new ProrationLine($description, $scaled);
    }

    /**
     * Convenience for the kept-anchor delta as a single {@see Money}, sharing the exact
     * rounding primitive {@see compute()} uses so the two never diverge.
     */
    public function prorate(Money $current, Money $new, BillingPeriod $period, DateTimeImmutable $at, GatewayRounding $rounding = GatewayRounding::HalfUp): Money
    {
        return $this->proratedLine('Prorated plan change', $new->minus($current), $period, $at, $rounding)->amount;
    }
}
