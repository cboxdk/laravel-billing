<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Support;

use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;

/**
 * Idempotency for recurring cycle grants — keyed on TIME, not a marker (ADR-0002).
 *
 * A recurring allotment (or a distributed slice) must be granted exactly once per
 * period even when an overlapping cron and webhook both fire. The robust dedupe is
 * the grant's own timestamp: a recurring grant already sitting in the pool with
 * `grantedAt >= periodStart` proves this cycle was already granted. The period
 * *start timestamp* is the key, NOT a stored period-id marker — a lagging cycle
 * mirror can stamp a stale period id, so a marker column would re-grant (or wrongly
 * skip); the timestamp cannot lie about when the credit landed. Any period-id
 * metadata a grant carries is audit-only and must never gate the grant.
 *
 * A grant is matched on `(org, pool, denomination, cadence)`: cadences MIX into one
 * pool (ADR-0013), so a Monthly allotment and a Daily drip into the same `included`
 * pool are distinct streams and must not suppress each other; likewise two meters'
 * included allowances share the `included` pool but differ by denomination. Only a
 * *recurring* cadence is a cycle grant — a one-off {@see GrantCadence::Once} top-up
 * is never the cycle allotment and must not suppress it.
 */
class CycleGrants
{
    /**
     * Has `org`'s recurring `cadence` allotment for `(pool, denomination)` already
     * been granted for the cycle that began at `periodStart`? True iff a matching
     * recurring grant carries `grantedAt >= periodStart`.
     *
     * @param  iterable<CreditGrant>  $existing  the grants already in the wallet
     */
    public static function alreadyGrantedThisCycle(
        iterable $existing,
        string $org,
        Pool $pool,
        Denomination $denomination,
        GrantCadence $cadence,
        int $periodStart,
    ): bool {
        return self::alreadyGrantedSlice($existing, $org, $pool, $denomination, $cadence, $periodStart, PHP_INT_MAX);
    }

    /**
     * The inverse of {@see alreadyGrantedThisCycle()} — a readable guard at the call
     * site: `if (CycleGrants::shouldGrant(...)) { $wallet->grant(...); }`.
     *
     * @param  iterable<CreditGrant>  $existing
     */
    public static function shouldGrant(
        iterable $existing,
        string $org,
        Pool $pool,
        Denomination $denomination,
        GrantCadence $cadence,
        int $periodStart,
    ): bool {
        return ! self::alreadyGrantedThisCycle($existing, $org, $pool, $denomination, $cadence, $periodStart);
    }

    /**
     * The per-slice form (ADR-0014): has the slice whose boundary opens the window
     * `[sliceStart, sliceEnd)` already been granted? A matching recurring grant with
     * `grantedAt` in that half-open window identifies exactly that slice, so each
     * cadence boundary is granted at most once even as `now` advances across many
     * slices in one period.
     *
     * @param  iterable<CreditGrant>  $existing
     */
    public static function alreadyGrantedSlice(
        iterable $existing,
        string $org,
        Pool $pool,
        Denomination $denomination,
        GrantCadence $cadence,
        int $sliceStart,
        int $sliceEnd,
    ): bool {
        if (! $cadence->isRecurring()) {
            return false;
        }

        foreach ($existing as $grant) {
            if ($grant->cadence === $cadence
                && $grant->org === $org
                && $grant->pool->sameAs($pool)
                && $grant->denomination->matches($denomination)
                && $grant->grantedAt >= $sliceStart
                && $grant->grantedAt < $sliceEnd) {
                return true;
            }
        }

        return false;
    }
}
