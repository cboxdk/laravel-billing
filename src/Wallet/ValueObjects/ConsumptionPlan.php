<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

/**
 * The result of the burn-down: which grants cover how much of a demand, in order.
 * `covered` may be less than `requested` when the wallet can't fully cover the
 * charge — the `shortfall` then falls through to the overage policy (money charge
 * or hard block).
 */
readonly class ConsumptionPlan
{
    /**
     * @param  list<Consumption>  $draws
     */
    public function __construct(
        public array $draws,
        public int $requested,
        public int $covered,
        public int $shortfall,
    ) {}

    public function isFullyCovered(): bool
    {
        return $this->shortfall === 0;
    }
}
