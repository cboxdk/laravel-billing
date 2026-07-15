<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Testing;

use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

/**
 * In-memory {@see MeterPolicyResolver} for tests. Register a {@see MeterPolicy} per
 * `(org, meter)`; anything unregistered resolves to `null` so the enforcer refuses
 * it (deny-by-default). Stands in for the entitlement-backed resolver the host wires
 * in production.
 */
class FakeMeterPolicyResolver implements MeterPolicyResolver
{
    /** @var array<string, MeterPolicy> keyed by "org:meter" */
    private array $policies = [];

    public function set(string $org, string $meter, MeterPolicy $policy): self
    {
        $this->policies[$org.':'.$meter] = $policy;

        return $this;
    }

    public function resolve(string $org, string $meter): ?MeterPolicy
    {
        return $this->policies[$org.':'.$meter] ?? null;
    }
}
