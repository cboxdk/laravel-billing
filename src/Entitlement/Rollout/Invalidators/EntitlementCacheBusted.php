<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Invalidators;

/**
 * The per-org cache-bust event: one organization's hot-path billing cache should be
 * invalidated NOW. The per-org rollout path dispatches exactly one of these per override
 * org; the bulk path dispatches none (it relies on the TTL).
 *
 * A host listens for this to evict / re-warm its own cache. The event carrying only the
 * org id is deliberate — this is the storm-prone signal a plan-wide rollout must NOT fan
 * out across every org. Immutable.
 */
readonly class EntitlementCacheBusted
{
    public function __construct(public string $organizationId) {}
}
