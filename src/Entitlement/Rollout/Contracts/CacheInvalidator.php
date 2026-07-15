<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Contracts;

/**
 * The per-org cache-bust — the hot-path billing cache invalidation, injected as a
 * collaborator so the two rollout paths can differ ONLY in whether they call it:
 *
 *  - the bulk path never calls it (invalidation rides the cache TTL), so a plan-wide
 *    rollout does not fire one bust per org and storm the cache;
 *  - the per-org path calls it exactly once per override org, so that org's tailored
 *    entitlements take effect immediately.
 *
 * Because it is a contract, a test binds a recording fake to assert the bulk cohort fired
 * ZERO busts and the override cohort fired exactly one per org, without any real cache.
 * The default binding dispatches a per-org cache-bust event; a host swaps its own cache
 * eviction / tag-flush in.
 */
interface CacheInvalidator
{
    /** Immediately invalidate the hot-path billing cache for ONE organization. */
    public function invalidate(string $organizationId): void;
}
