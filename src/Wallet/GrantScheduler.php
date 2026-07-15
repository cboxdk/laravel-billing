<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet;

use Cbox\Billing\Wallet\Contracts\ExpiryPolicy;
use Cbox\Billing\Wallet\Support\CycleGrants;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\PlanGrant;
use DateTimeImmutable;

/**
 * Expands a {@see PlanGrant} across a billing period into the concrete {@see CreditGrant}
 * lots that should exist — one lot per cadence slice (ADR-0013/ADR-0014). A pure
 * planner: it returns the lots that are DUE now (their boundary has vested) and are
 * not yet in the wallet; the caller deposits them.
 *
 * Idempotent per slice (ADR-0002): each lot's `grantedAt` is its slice boundary, and
 * {@see CycleGrants::alreadyGrantedSlice()} dedupes on that timestamp window, so an
 * overlapping cron and webhook — or a re-run after `now` advances across several
 * slices — grant each boundary at most once. Each lot's `expiresAt` is derived from
 * the grant's {@see ExpiryPolicy} over the slice
 * boundary and its cadence period end, giving rollover (Duration) vs reset
 * (EndOfPeriod) purely through lot-attributed expiry (ADR-0006).
 */
class GrantScheduler
{
    /**
     * The lots of `$grant` that have vested by `$now` (slice boundary ≤ `$now`) and
     * are not already present in `$existing`. Grant them into the wallet.
     *
     * @param  iterable<CreditGrant>  $existing  the grants already in the wallet
     * @return list<CreditGrant>
     */
    public function due(
        PlanGrant $grant,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $now,
        iterable $existing = [],
    ): array {
        $existing = is_array($existing) ? $existing : iterator_to_array($existing, false);
        $nowMs = $now->getTimestamp() * 1000;
        $cadence = $grant->amount->cadence();

        $due = [];
        foreach ($grant->amount->slices($periodStart, $periodEnd) as $slice) {
            if ($slice->boundaryMs > $nowMs) {
                continue; // not yet vested
            }

            if (CycleGrants::alreadyGrantedSlice(
                $existing,
                $grant->org,
                $grant->pool,
                $grant->denomination,
                $cadence,
                $slice->boundaryMs,
                $slice->periodEndMs,
            )) {
                continue; // this slice was already granted
            }

            $due[] = new CreditGrant(
                id: $grant->id.':'.$slice->boundaryMs,
                org: $grant->org,
                pool: $grant->pool,
                denomination: $grant->denomination,
                remaining: $slice->amount,
                expiresAt: $grant->expiry->expiresAt($slice->boundaryMs, $slice->periodEndMs),
                priority: $grant->priority,
                grantedAt: $slice->boundaryMs,
                kind: $grant->kind,
                cadence: $cadence,
            );
        }

        return $due;
    }
}
