<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\CreditDelta;
use Cbox\Billing\Subscription\Proration\Proration;
use DateTimeImmutable;

/**
 * The full consequence of a plan change, for a confirm step. It carries the exact
 * {@see Proration} the charge will commit — the preview is the charge, not a parallel
 * estimate. When money is due now (an upgrade, or a reset that charges more than it
 * credits) the amount is taxed into `dueNowQuote`; a deferred downgrade or a net
 * credit leaves `dueNowQuote` null. `newRecurring` is the price from the next full
 * period; `effectiveAt` is when the change lands.
 *
 * Beside the money it carries the {@see CreditDelta} — units forfeited / granted /
 * carried / left-negative — so the confirm step shows the credit consequence too
 * (ADR-0011). `irreversibilityWarning` is non-null only when the *current* plan is
 * legacy: switching away cannot be undone (ADR-0010). `guidance` echoes any note the
 * policy attached to the allowed transition (e.g. "requires migration").
 */
readonly class PlanChangePreview
{
    public function __construct(
        public bool $isUpgrade,
        public Money $proratedNet,
        public ?Quote $dueNowQuote,
        public Money $newRecurring,
        public DateTimeImmutable $effectiveAt,
        public Proration $proration,
        public CreditDelta $creditDelta,
        public ?string $irreversibilityWarning = null,
        public ?string $guidance = null,
    ) {}

    /** The current plan is legacy: the change cannot be reversed. */
    public function isIrreversible(): bool
    {
        return $this->irreversibilityWarning !== null;
    }
}
