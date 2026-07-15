<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Sources;

use Cbox\Billing\Metering\Contracts\AllowanceLeaseSource;
use Cbox\Billing\Metering\LeasedEnforcement;
use Cbox\Billing\Metering\ValueObjects\AllowanceLease;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Closure;

/**
 * The combined-balance lease (ADR-0013): a node leases a meter's budget from the
 * Wallet's **combined spendable balance** — the sum of the meter's spendable pools
 * this period (`included` allowance + `promotional` credit + `purchased` top-ups),
 * each floored at zero so a PAYG sink's debt never shrinks the leasable budget.
 * Included allowance and credits therefore fund the SAME meter through one budget, so
 * the metering hot path enforces a single hard limit over the unified balance while
 * staying local/atomic (ADR-0005): {@see LeasedEnforcement}
 * leases disjoint slices of what this source grants and the atomic disjoint-slice
 * claim still governs exactly-once consumption within a slice.
 *
 * Leasing is PESSIMISTIC: outstanding leases are tracked per `(org, meter)` and can
 * never exceed the combined balance, so the sum of node-held leases is a true hard
 * limit — the same invariant as any {@see AllowanceLeaseSource}, now derived from the
 * Wallet rather than a standalone allowance.
 */
class WalletAllowanceLeaseSource implements AllowanceLeaseSource
{
    /** @var array<string, int> currently leased-out per org:meter */
    private array $leased = [];

    /** @var list<Pool> */
    private array $spendablePools;

    /**
     * @param  list<Pool>|null  $spendablePools  the spendable pools summed into the combined
     *                                           balance (defaults to the standard catalog's
     *                                           included → promotional → purchased)
     * @param  (Closure(): int)|null  $clock  millisecond-epoch clock (deterministic in tests)
     */
    public function __construct(
        private readonly Wallet $wallet,
        ?array $spendablePools = null,
        ?Closure $clock = null,
    ) {
        $this->spendablePools = $spendablePools ?? Pools::defaultConsumptionOrder();
        $this->clock = $clock ?? static fn (): int => (int) round(microtime(true) * 1000);
    }

    /** @var Closure(): int */
    private Closure $clock;

    public function lease(string $org, string $meter, int $want): AllowanceLease
    {
        if ($want <= 0) {
            return new AllowanceLease($org, $meter, 0);
        }

        $key = $this->key($org, $meter);
        $available = $this->combinedBalance($org, $meter) - ($this->leased[$key] ?? 0);
        $granted = max(0, min($want, $available));

        $this->leased[$key] = ($this->leased[$key] ?? 0) + $granted;

        return new AllowanceLease($org, $meter, $granted);
    }

    public function giveBack(string $org, string $meter, int $unused): void
    {
        if ($unused <= 0) {
            return;
        }

        $key = $this->key($org, $meter);
        $this->leased[$key] = max(0, ($this->leased[$key] ?? 0) - $unused);
    }

    /** The meter's combined spendable balance this period — Σ over spendable pools, each floored at 0. */
    public function combinedBalance(string $org, string $meter): int
    {
        $now = ($this->clock)();
        $denomination = Denomination::unit($meter);

        $total = 0;
        foreach ($this->spendablePools as $pool) {
            if (! $pool->spendable) {
                continue;
            }

            $total += max(0, $this->wallet->balance($org, $pool, $denomination, $now));
        }

        return $total;
    }

    /** Units currently leased out (held by nodes) for (org, meter). */
    public function leasedOut(string $org, string $meter): int
    {
        return $this->leased[$this->key($org, $meter)] ?? 0;
    }

    private function key(string $org, string $meter): string
    {
        return $org.':'.$meter;
    }
}
