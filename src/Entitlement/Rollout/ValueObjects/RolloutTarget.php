<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\ValueObjects;

use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

/**
 * One org in the rollout cohort, carrying whether it has tailored entitlements. This is
 * the flag the rollout service splits the cohort on:
 *
 *  - no overrides ({@see hasOverride()} false) → the BULK, event-suppressed path (plan
 *    change applied verbatim in a chunk, no per-org cache-bust, TTL invalidates);
 *  - some overrides → the PER-ORG audited path (plan change overlaid with these overrides,
 *    written individually, cache busted immediately).
 *
 * `overrides` is the org's tailored `{meter => MeterPolicy}` that wins over the plan's
 * baseline for the same meter. The host annotates each org (it knows which orgs carry
 * overrides); the service does not guess. Immutable.
 */
readonly class RolloutTarget
{
    /**
     * @param  array<string, MeterPolicy>  $overrides  tailored per-meter policies that
     *                                                 override the plan baseline
     */
    public function __construct(
        public string $org,
        public array $overrides = [],
    ) {}

    /** An org with no tailored entitlements — routed to the bulk, event-suppressed path. */
    public static function bulk(string $org): self
    {
        return new self($org);
    }

    /**
     * An org with tailored entitlements — routed to the per-org audited path.
     *
     * @param  array<string, MeterPolicy>  $overrides
     */
    public static function withOverrides(string $org, array $overrides): self
    {
        return new self($org, $overrides);
    }

    public function hasOverride(): bool
    {
        return $this->overrides !== [];
    }
}
