<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * The MRR bridge for one currency between two periods, decomposed into the five
 * standard movement components. `$contraction` and `$churn` are stored as positive
 * magnitudes; the accounting identity subtracts them:
 *
 *   startMrr + new + expansion − contraction − churn + reactivation = endMrr
 *
 * {@see reconciles()} asserts that identity holds exactly (it does by construction).
 * Currencies are never mixed — one waterfall is one currency.
 */
readonly class MrrWaterfall
{
    public function __construct(
        public string $currency,
        public Money $startMrr,
        public Money $endMrr,
        public Money $new,
        public Money $expansion,
        public Money $contraction,
        public Money $churn,
        public Money $reactivation,
    ) {}

    /** Net MRR change over the window (endMrr − startMrr), signed. */
    public function netChange(): Money
    {
        return $this->endMrr->minus($this->startMrr);
    }

    /** Whether the five components reconstruct endMrr from startMrr exactly. */
    public function reconciles(): bool
    {
        $reconstructed = $this->startMrr
            ->plus($this->new)
            ->plus($this->expansion)
            ->minus($this->contraction)
            ->minus($this->churn)
            ->plus($this->reactivation);

        return $reconstructed->equals($this->endMrr);
    }

    /** The same decomposition annualised (each component × 12). */
    public function toArr(): ArrWaterfall
    {
        return ArrWaterfall::fromMrr($this);
    }
}
