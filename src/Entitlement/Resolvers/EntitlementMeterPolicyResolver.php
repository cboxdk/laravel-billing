<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Resolvers;

use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

/**
 * The entitlement module's answer to "what is this `(org, meter)` bucket granted?" —
 * the concrete {@see MeterPolicyResolver} the metering enforcer resolves policy
 * through. Entitlement decides `enabled?` + isolated allowance + weight + overage per
 * dimension; the host feeds those decisions in (from plan/subscription state) via
 * {@see grant()}, and the enforcer reads them back.
 *
 * **Deny-by-default:** a `(org, meter)` with no granted policy resolves to `null`, so
 * the enforcer refuses it — an unentitled dimension is never silently metered.
 */
class EntitlementMeterPolicyResolver implements MeterPolicyResolver
{
    /** @var array<string, MeterPolicy> keyed by "org:meter" */
    private array $policies = [];

    public function grant(string $org, string $meter, MeterPolicy $policy): self
    {
        $this->policies[$this->key($org, $meter)] = $policy;

        return $this;
    }

    /** Revoke a bucket's policy, returning it to deny-by-default (refused). */
    public function revoke(string $org, string $meter): self
    {
        unset($this->policies[$this->key($org, $meter)]);

        return $this;
    }

    public function resolve(string $org, string $meter): ?MeterPolicy
    {
        return $this->policies[$this->key($org, $meter)] ?? null;
    }

    private function key(string $org, string $meter): string
    {
        return $org.':'.$meter;
    }
}
