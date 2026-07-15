<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

/**
 * The outcome of an expiry sweep or a forfeiture: the set of {@see CreditRemoval}s
 * that were applied, in the order they were applied. Empty when nothing was
 * removed — which is exactly what a re-run of an already-swept (or already-forfeited)
 * wallet returns, so callers can treat an empty report as "idempotent no-op".
 */
readonly class RemovalReport
{
    /**
     * @param  list<CreditRemoval>  $removals
     */
    public function __construct(
        public array $removals = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->removals === [];
    }

    public function count(): int
    {
        return count($this->removals);
    }

    /** Total amount removed across every lot in this report (always `>= 0`). */
    public function total(): int
    {
        $total = 0;

        foreach ($this->removals as $removal) {
            $total += $removal->amount;
        }

        return $total;
    }
}
