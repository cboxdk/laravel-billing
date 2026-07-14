<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet;

use Cbox\Billing\Wallet\ValueObjects\Consumption;
use Cbox\Billing\Wallet\ValueObjects\ConsumptionPlan;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

/**
 * The credit burn-down engine — a pure planner. Given a demand and an org's
 * grants, it picks which grants cover the charge and in what order, without
 * mutating anything: the caller applies the returned {@see ConsumptionPlan}
 * (decrement grants + post each drawdown to the ledger).
 *
 * Order (deterministic, so a charge is reproducible):
 *   1. Denomination match — only grants in the demand's denomination are eligible.
 *   2. Soonest-expiring first (never-expiring last) — use-it-or-lose-it.
 *   3. Lowest priority number first — promotional before what the customer paid for.
 *   4. Oldest grant first — the tiebreaker.
 * A single demand may draw across multiple grants; any uncovered remainder is the
 * plan's shortfall (→ money charge or hard block, per the overage policy).
 */
class CreditConsumer
{
    /**
     * @param  list<CreditGrant>  $grants
     */
    public function plan(string $org, Denomination $demand, int $amount, array $grants, int $now): ConsumptionPlan
    {
        $remaining = max(0, $amount);

        $candidates = array_values(array_filter(
            $grants,
            fn (CreditGrant $g): bool => $g->org === $org
                && $g->denomination->matches($demand)
                && $g->isActive($now),
        ));

        usort($candidates, static function (CreditGrant $a, CreditGrant $b): int {
            return [$a->expiresAt ?? PHP_INT_MAX, $a->priority, $a->grantedAt]
                <=> [$b->expiresAt ?? PHP_INT_MAX, $b->priority, $b->grantedAt];
        });

        $draws = [];
        foreach ($candidates as $grant) {
            if ($remaining === 0) {
                break;
            }

            $take = min($grant->remaining, $remaining);
            if ($take > 0) {
                $draws[] = new Consumption($grant->id, $take);
                $remaining -= $take;
            }
        }

        return new ConsumptionPlan(
            draws: $draws,
            requested: max(0, $amount),
            covered: max(0, $amount) - $remaining,
            shortfall: $remaining,
        );
    }
}
