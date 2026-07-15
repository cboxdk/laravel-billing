<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Support;

use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Pool;

/**
 * Idempotency for recurring cycle grants — keyed on TIME, not a marker (ADR-0002).
 *
 * A recurring allotment must be granted exactly once per billing cycle even when an
 * overlapping cron and webhook both fire. The robust dedupe is the grant's own
 * timestamp: a recurring grant already sitting in the pool with
 * `grantedAt >= periodStart` proves this cycle was already granted. The period
 * *start timestamp* is the key, NOT a stored period-id marker — a lagging cycle
 * mirror can stamp a stale period id, so a marker column would re-grant (or wrongly
 * skip); the timestamp cannot lie about when the credit landed. Any period-id
 * metadata a grant carries is audit-only and must never gate the grant.
 *
 * Interpretation: "a grant into the pool" is read as the pool's *recurring* grant
 * ({@see GrantCadence::Recurring}) — a one-off top-up into the same pool is not the
 * cycle allotment and must not suppress it. Callers key on `(org, pool)`; the helper
 * is a pure predicate over the grants already in the wallet, so it works against the
 * in-memory fake and a durable wallet alike.
 */
class CycleGrants
{
    /**
     * Has `org`'s recurring allotment for `pool` already been granted for the cycle
     * that began at `periodStart`? True iff a recurring grant into that pool carries
     * `grantedAt >= periodStart`.
     *
     * @param  iterable<CreditGrant>  $existing  the grants already in the wallet
     */
    public static function alreadyGrantedThisCycle(iterable $existing, string $org, Pool $pool, int $periodStart): bool
    {
        foreach ($existing as $grant) {
            if ($grant->cadence === GrantCadence::Recurring
                && $grant->org === $org
                && $grant->pool->sameAs($pool)
                && $grant->grantedAt >= $periodStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * The inverse of {@see alreadyGrantedThisCycle()} — a readable guard at the call
     * site: `if (CycleGrants::shouldGrant(...)) { $wallet->grant(...); }`.
     *
     * @param  iterable<CreditGrant>  $existing
     */
    public static function shouldGrant(iterable $existing, string $org, Pool $pool, int $periodStart): bool
    {
        return ! self::alreadyGrantedThisCycle($existing, $org, $pool, $periodStart);
    }
}
