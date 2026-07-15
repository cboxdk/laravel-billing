<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Testing;

use Cbox\Billing\Metering\Buffers\ArrayUsageBuffer;
use Cbox\Billing\Metering\Contracts\LocalStore;
use Cbox\Billing\Metering\Enums\InfraFailurePolicy;
use Cbox\Billing\Metering\LeasedEnforcement;
use Cbox\Billing\Metering\Stores\CacheLocalStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * Wire up the app-local enforcement hot path in tests:
 *
 *     $source = $this->leaseSource()->grant('org_a', 'api.calls', 1_000);
 *     $enforcement = $this->makeEnforcement(refillSize: 100);
 *     $res = $enforcement->reserve('org_a', 'api.calls', 5);
 *     $enforcement->commit($res, 5);
 *
 * Ids and the clock are deterministic so buffered events assert cleanly.
 */
trait InteractsWithMetering
{
    private ?FakeAllowanceLeaseSource $leaseSource = null;

    private ?ArrayUsageBuffer $usageBuffer = null;

    private ?FakeMeterPolicyResolver $meterPolicies = null;

    private ?RecordingEnforcementSignals $enforcementSignals = null;

    private int $meteringIdSeq = 0;

    protected function leaseSource(): FakeAllowanceLeaseSource
    {
        return $this->leaseSource ??= new FakeAllowanceLeaseSource;
    }

    protected function usageBuffer(): ArrayUsageBuffer
    {
        return $this->usageBuffer ??= new ArrayUsageBuffer;
    }

    protected function meterPolicies(): FakeMeterPolicyResolver
    {
        return $this->meterPolicies ??= new FakeMeterPolicyResolver;
    }

    protected function enforcementSignals(): RecordingEnforcementSignals
    {
        return $this->enforcementSignals ??= new RecordingEnforcementSignals;
    }

    /**
     * Build the enforcer. Pass a custom `$store` (e.g. {@see OutageLocalStore}) to
     * simulate an infrastructure outage, and `$infraPolicy` to flip fail-open/closed —
     * the two knobs an ADR-0004 outcome test exercises. Emitted infra signals are
     * captured on {@see enforcementSignals()}.
     */
    protected function makeEnforcement(
        int $refillSize = 100,
        string $service = 'test-service',
        ?LocalStore $store = null,
        InfraFailurePolicy $infraPolicy = InfraFailurePolicy::Allow,
    ): LeasedEnforcement {
        return new LeasedEnforcement(
            store: $store ?? new CacheLocalStore(new Repository(new ArrayStore)),
            source: $this->leaseSource(),
            buffer: $this->usageBuffer(),
            service: $service,
            refillSize: $refillSize,
            ids: fn (): string => 'id-'.(++$this->meteringIdSeq),
            clock: static fn (): int => 1_700_000_000_000,
            policies: $this->meterPolicies(),
            signals: $this->enforcementSignals(),
            infraPolicy: $infraPolicy,
        );
    }
}
