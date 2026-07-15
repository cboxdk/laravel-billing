<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet;

use Cbox\Billing\Wallet\ValueObjects\Consumption;
use Cbox\Billing\Wallet\ValueObjects\ConsumptionPlan;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;

/**
 * The credit burn-down engine — a pure planner. Given a demand, an org's grants,
 * and an ordered list of pools (the consumption order), it picks which grants cover
 * the charge and in what order without mutating anything: the caller applies the
 * returned {@see ConsumptionPlan} (decrement grants + post each drawdown to the ledger).
 *
 * Pools are consumed in the given order; only spendable pools are consumed at all.
 * Within a pool the order is deterministic, so a charge is reproducible:
 *   1. Soonest-expiring first (never-expiring last) — use-it-or-lose-it.
 *   2. Lowest priority number first — promotional before what the customer paid for.
 *   3. Oldest grant first — the tiebreaker.
 *
 * If the last pool in the order `mayGoNegative`, any remainder the spendable pools
 * could not cover is absorbed there as a negative draw (the PAYG sink). Otherwise the
 * remainder is the plan's shortfall (→ money charge or hard block, per overage policy).
 */
class CreditConsumer
{
    /**
     * @param  list<CreditGrant>  $grants
     * @param  list<Pool>  $poolOrder
     */
    public function plan(string $org, Denomination $demand, int $amount, array $grants, array $poolOrder, int $now): ConsumptionPlan
    {
        $requested = max(0, $amount);
        $remaining = $requested;

        // Grants for this org, in the demand's denomination, still live.
        $eligible = array_values(array_filter(
            $grants,
            fn (CreditGrant $g): bool => $g->org === $org
                && $g->denomination->matches($demand)
                && $g->isActive($now),
        ));

        /** @var array<string, array{amount: int, pool: string}> $draws keyed by grant id, insertion-ordered */
        $draws = [];

        foreach ($poolOrder as $pool) {
            if ($remaining === 0) {
                break;
            }
            if (! $pool->spendable) {
                continue; // non-spendable pools are never consumed
            }

            foreach ($this->grantsIn($pool, $eligible, spendableOnly: true) as $grant) {
                if ($remaining === 0) {
                    break;
                }

                $take = min($grant->remaining, $remaining);
                if ($take > 0) {
                    $draws[$grant->id] = [
                        'amount' => ($draws[$grant->id]['amount'] ?? 0) + $take,
                        'pool' => $pool->key,
                    ];
                    $remaining -= $take;
                }
            }
        }

        // PAYG sink: the last pool in the order absorbs the uncovered remainder as
        // debt when it may go negative — provided it holds a grant to carry it.
        if ($remaining > 0 && $poolOrder !== []) {
            $last = $poolOrder[array_key_last($poolOrder)];
            if ($last->mayGoNegative) {
                $sink = $this->grantsIn($last, $eligible, spendableOnly: false);
                if ($sink !== []) {
                    $target = $sink[0];
                    $draws[$target->id] = [
                        'amount' => ($draws[$target->id]['amount'] ?? 0) + $remaining,
                        'pool' => $last->key,
                    ];
                    $remaining = 0;
                }
            }
        }

        $consumptions = [];
        foreach ($draws as $grantId => $draw) {
            $consumptions[] = new Consumption((string) $grantId, $draw['amount'], $draw['pool']);
        }

        return new ConsumptionPlan(
            draws: $consumptions,
            requested: $requested,
            covered: $requested - $remaining,
            shortfall: $remaining,
        );
    }

    /**
     * Grants living in `$pool`, sorted deterministically (soonest-expiring → priority
     * → oldest). With `$spendableOnly`, only grants holding a positive remainder are
     * returned (spending candidates); without it, every live grant is returned (so the
     * PAYG sink can pick a grant to carry a negative draw).
     *
     * @param  list<CreditGrant>  $eligible
     * @return list<CreditGrant>
     */
    private function grantsIn(Pool $pool, array $eligible, bool $spendableOnly): array
    {
        $in = array_values(array_filter(
            $eligible,
            fn (CreditGrant $g): bool => $g->pool->sameAs($pool) && (! $spendableOnly || $g->remaining > 0),
        ));

        usort($in, static fn (CreditGrant $a, CreditGrant $b): int => [$a->expiresAt ?? PHP_INT_MAX, $a->priority, $a->grantedAt]
            <=> [$b->expiresAt ?? PHP_INT_MAX, $b->priority, $b->grantedAt]);

        return $in;
    }
}
