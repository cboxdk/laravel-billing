<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Wallet\Contracts\ExpiryPolicy;
use Cbox\Billing\Wallet\Contracts\GrantAmount;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\GrantScheduler;
use Cbox\Billing\Wallet\Support\Pools;

/**
 * A plan's entitlement grant (ADR-0013's unified `{target, amount, pool, cadence}`):
 * a recurring (or one-off) grant of `amount` into `pool`, denominated in
 * `denomination` (a meter's units for an included allowance, an ISO currency for
 * money credits). The {@see GrantAmount} carries the cadence and Fixed/Distributed
 * mode; the {@see ExpiryPolicy} turns each granted lot into rollover-vs-reset.
 *
 * An **included metered allowance is just a plan grant into the `included` pool**
 * (ADR-0013) — {@see includedAllowance()} names that shape — so included allowances
 * and credits are expanded by the same {@see GrantScheduler} and
 * burn down in one order.
 */
readonly class PlanGrant
{
    public function __construct(
        public string $id,
        public string $org,
        public Pool $pool,
        public Denomination $denomination,
        public GrantAmount $amount,
        public ExpiryPolicy $expiry = new NeverExpires,
        public int $priority = 0,
        public GrantKind $kind = GrantKind::Base,
    ) {}

    /**
     * A meter's included allowance as a recurring grant into the `included` pool,
     * meter-denominated (ADR-0013). Defaults to `EndOfPeriod` expiry — the included
     * allowance resets each cadence period (use-it-or-lose-it) unless a plan opts into
     * rollover with a `Duration`.
     */
    public static function includedAllowance(
        string $id,
        string $org,
        string $meter,
        GrantAmount $amount,
        ExpiryPolicy $expiry = new EndOfPeriod,
        int $priority = 0,
    ): self {
        return new self(
            id: $id,
            org: $org,
            pool: Pools::included(),
            denomination: Denomination::unit($meter),
            amount: $amount,
            expiry: $expiry,
            priority: $priority,
        );
    }
}
