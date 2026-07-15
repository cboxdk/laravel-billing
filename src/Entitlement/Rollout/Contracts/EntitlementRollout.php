<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Contracts;

use Cbox\Billing\Entitlement\Rollout\ValueObjects\PlanEntitlementChange;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutReport;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutTarget;

/**
 * Rolls a plan-wide entitlement change out to a cohort of orgs WITHOUT storming the
 * hot-path billing cache.
 *
 * The problem it exists to solve: firing a per-org cache-invalidation event for every org
 * on a plan (imagine 100k+ orgs) stampedes the cache that the metering hot path reads
 * through. So the service splits the cohort and routes each org to one of two explicit
 * paths ({@see RolloutTarget::hasOverride()} decides which):
 *
 *  - BULK / event-suppressed — orgs WITHOUT overrides. The change is applied in chunks
 *    (each chunk one atomic transaction), and NO per-org cache-bust event is emitted:
 *    invalidation is left to the cache TTL. One bulk summary is logged, not N events.
 *  - PER-ORG audited — orgs WITH overrides. Each is resolved individually (plan baseline
 *    overlaid with its tailored grants), written, and its cache busted IMMEDIATELY so the
 *    tailored entitlements take effect now rather than waiting out the TTL.
 *
 * Idempotent: re-applying the same {@see PlanEntitlementChange::$id} is safe (writes and
 * audit rows upsert, they do not duplicate). Deny-by-default is unchanged — the rollout
 * only writes the grants it is given.
 */
interface EntitlementRollout
{
    /**
     * Apply `$change` across `$cohort`, routing each org by whether it has overrides, and
     * return the summary.
     *
     * @param  iterable<RolloutTarget>  $cohort
     */
    public function apply(PlanEntitlementChange $change, iterable $cohort): RolloutReport;
}
