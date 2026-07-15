<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\ValueObjects;

/**
 * The summary of one rollout — the single record the service logs and returns instead of
 * N per-org events. It makes the storm-avoidance auditable at a glance:
 *
 *  - `bulkOrgs`     — orgs updated through the event-suppressed path (no cache-bust);
 *  - `overrideOrgs` — orgs updated through the per-org audited path;
 *  - `chunks`       — how many atomic chunk-transactions the bulk cohort was applied in;
 *  - `bustsFired`   — how many immediate per-org cache-busts fired.
 *
 * The load-bearing invariant is `bustsFired === overrideOrgs`: the bulk cohort fires ZERO
 * busts (it relies on TTL), so every bust is attributable to exactly one override org.
 * Immutable.
 */
readonly class RolloutReport
{
    public function __construct(
        public string $rolloutId,
        public string $plan,
        public int $bulkOrgs,
        public int $overrideOrgs,
        public int $chunks,
        public int $bustsFired,
    ) {}

    public function totalOrgs(): int
    {
        return $this->bulkOrgs + $this->overrideOrgs;
    }
}
