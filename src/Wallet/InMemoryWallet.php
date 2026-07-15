<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet;

use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Enums\RemovalReason;
use Cbox\Billing\Wallet\ValueObjects\ConsumptionPlan;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\CreditRemoval;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Cbox\Billing\Wallet\ValueObjects\RemovalReport;
use Closure;

/**
 * In-memory {@see Wallet} — proves the grant lifecycle: hold grants across their
 * pools, run the pool-order-aware burn-down, and apply it by decrementing the drawn
 * grants (a PAYG-sink draw may push its grant negative). A durable wallet (Eloquent)
 * additionally posts monetary drawdowns to the ledger; the contract and burn-down
 * are identical.
 */
class InMemoryWallet implements Wallet
{
    /** @var array<string, CreditGrant> keyed by grant id */
    private array $grants = [];

    public function __construct(private readonly CreditConsumer $consumer = new CreditConsumer) {}

    public function grant(CreditGrant $grant): void
    {
        $this->grants[$grant->id] = $grant;
    }

    public function consume(string $org, Denomination $denomination, int $amount, array $poolOrder, int $now): ConsumptionPlan
    {
        $plan = $this->consumer->plan($org, $denomination, $amount, array_values($this->grants), $poolOrder, $now);

        foreach ($plan->draws as $draw) {
            $grant = $this->grants[$draw->grantId];
            $this->grants[$draw->grantId] = $grant->withRemaining($grant->remaining - $draw->amount);
        }

        return $plan;
    }

    public function balance(string $org, Pool $pool, Denomination $denomination, int $now): int
    {
        $total = 0;

        foreach ($this->grants as $grant) {
            if ($grant->org === $org
                && $grant->pool->sameAs($pool)
                && $grant->denomination->matches($denomination)
                && $grant->isActive($now)) {
                $total += $grant->remaining;
            }
        }

        return $total;
    }

    public function expire(string $org, int $now, int $lookback = Wallet::DEFAULT_LOOKBACK): RemovalReport
    {
        $floor = $now - max(0, $lookback);

        return $this->remove(
            $org,
            $now,
            RemovalReason::Expired,
            static fn (CreditGrant $grant): bool => $grant->hasExpired($now)
                && $grant->expiresAt !== null
                && $grant->expiresAt > $floor,
        );
    }

    public function forfeit(string $org, int $now): RemovalReport
    {
        return $this->remove(
            $org,
            $now,
            RemovalReason::Forfeited,
            static fn (CreditGrant $grant): bool => $grant->pool->forfeitsOnCancel,
        );
    }

    /**
     * The shared core of expiry and forfeiture: zero the positive remainder of every
     * of `org`'s lots the `$selects` predicate picks, recording each removal. Only a
     * positive remainder is ever removed, so the operation is idempotent (a swept lot
     * sits at 0 and is skipped next time) and can never turn a `mayGoNegative` sink's
     * debt into a credit or deepen it.
     *
     * @param  Closure(CreditGrant): bool  $selects
     */
    private function remove(string $org, int $now, RemovalReason $reason, Closure $selects): RemovalReport
    {
        $removals = [];

        foreach ($this->grants as $id => $grant) {
            if ($grant->org !== $org || $grant->remaining <= 0 || ! $selects($grant)) {
                continue;
            }

            $removals[] = new CreditRemoval(
                grantId: $grant->id,
                org: $grant->org,
                pool: $grant->pool->key,
                denomination: $grant->denomination,
                amount: $grant->remaining,
                reason: $reason,
                at: $now,
            );

            $this->grants[$id] = $grant->withRemaining(0);
        }

        return new RemovalReport($removals);
    }
}
