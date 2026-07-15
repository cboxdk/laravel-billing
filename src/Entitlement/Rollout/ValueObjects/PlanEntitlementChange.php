<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\ValueObjects;

use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

/**
 * A plan-wide entitlement change to roll out: the new per-meter policies a plan grants,
 * carried alongside the plan it belongs to and a stable rollout `id`.
 *
 * The `id` is what ties every audit row this rollout writes together and what makes the
 * rollout idempotent: re-applying the same `id` upserts the same rows rather than writing
 * duplicates, so a re-run (after a partial crash, say) is safe.
 *
 * `grants` is the plan's baseline `{meter => MeterPolicy}`. The bulk cohort receives it
 * verbatim; an override org receives it overlaid with that org's own tailored grants.
 * Immutable.
 */
readonly class PlanEntitlementChange
{
    /**
     * @param  array<string, MeterPolicy>  $grants  the plan's per-meter baseline policies
     */
    public function __construct(
        public string $id,
        public string $plan,
        public array $grants,
    ) {}
}
