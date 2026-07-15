<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Dunning\Enums\InvoicePaymentState;
use DateTimeImmutable;

/**
 * One issued invoice as dunning sees it: its number, the instant it fell due, its
 * payment state, and the amount still owed. The due date is an explicit instant — the
 * policy compares timestamps, never wall-clock dates, so no hidden timezone can shift
 * whether an invoice is inside or outside the grace window.
 */
readonly class DelinquentInvoice
{
    public function __construct(
        public string $number,
        public DateTimeImmutable $dueAt,
        public InvoicePaymentState $state,
        public Money $amountDue,
    ) {}

    /** Still an outstanding (open) debt. */
    public function isOutstanding(): bool
    {
        return $this->state->isOutstanding();
    }

    /** Written off — blocks an account from being restored to access. */
    public function isUncollectible(): bool
    {
        return $this->state->blocksRestore();
    }

    /** Whole seconds this invoice is past due as of `$now` (negative if not yet due). */
    public function secondsPastDue(DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $this->dueAt->getTimestamp();
    }

    /** Whole days this invoice is past due as of `$now` (floored; 0 before a full day). */
    public function daysPastDue(DateTimeImmutable $now): int
    {
        $seconds = $this->secondsPastDue($now);

        return $seconds <= 0 ? 0 : intdiv($seconds, 86400);
    }

    /**
     * Whether this invoice is past due by MORE than the grace window — i.e. old enough
     * to dun. A just-missed payment (inside the window) returns false and is ignored.
     */
    public function isDunnable(DateTimeImmutable $now, DunningConfig $config): bool
    {
        return $this->isOutstanding() && $this->secondsPastDue($now) >= $config->graceSeconds();
    }
}
