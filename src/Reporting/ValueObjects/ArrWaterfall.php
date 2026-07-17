<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * The ARR bridge for one currency — the {@see MrrWaterfall} with every component
 * annualised (× 12). Because ×12 is a linear scaling of exact minor-unit amounts,
 * the same accounting identity holds:
 *
 *   startArr + new + expansion − contraction − churn + reactivation = endArr
 */
readonly class ArrWaterfall
{
    public function __construct(
        public string $currency,
        public Money $startArr,
        public Money $endArr,
        public Money $new,
        public Money $expansion,
        public Money $contraction,
        public Money $churn,
        public Money $reactivation,
    ) {}

    public static function fromMrr(MrrWaterfall $mrr): self
    {
        return new self(
            $mrr->currency,
            $mrr->startMrr->multipliedBy(12),
            $mrr->endMrr->multipliedBy(12),
            $mrr->new->multipliedBy(12),
            $mrr->expansion->multipliedBy(12),
            $mrr->contraction->multipliedBy(12),
            $mrr->churn->multipliedBy(12),
            $mrr->reactivation->multipliedBy(12),
        );
    }

    /** Net ARR change over the window (endArr − startArr), signed. */
    public function netChange(): Money
    {
        return $this->endArr->minus($this->startArr);
    }

    /** Whether the five components reconstruct endArr from startArr exactly. */
    public function reconciles(): bool
    {
        $reconstructed = $this->startArr
            ->plus($this->new)
            ->plus($this->expansion)
            ->minus($this->contraction)
            ->minus($this->churn)
            ->plus($this->reactivation);

        return $reconstructed->equals($this->endArr);
    }
}
