<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Testing;

use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Audit\DefaultEntitlementAudit;
use Cbox\Billing\Entitlement\Audit\Sources\InMemoryExpectedEntitlements;
use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;

/**
 * Wire up the entitlement audit in tests:
 *
 *     $resolver = $this->grantedResolver();                 // the "rows that exist"
 *     $resolver->grant('org_a', 'api.calls', MeterPolicy::unlimited());
 *
 *     $signals = $this->auditSignals();                     // RecordingEntitlementAuditSignals
 *     $audit   = $this->makeEntitlementAudit($resolver, $signals);
 *
 *     $expected = $this->expected()->expect('org_a', 'pro', ['api.calls', 'seats']);
 *     $report   = $audit->audit($expected->targets());
 *
 *     expect($report->hasOutage())->toBeTrue();             // 'seats' row is missing
 *     expect($signals->outageCount())->toBe(1);
 *
 * The resolver is the same {@see EntitlementMeterPolicyResolver} the enforcer reads, so a
 * missing `grant()` is exactly the missing-row outage in production.
 */
trait InteractsWithEntitlementAudit
{
    private ?EntitlementMeterPolicyResolver $auditResolver = null;

    private ?RecordingEntitlementAuditSignals $auditSignalsFake = null;

    protected function grantedResolver(): EntitlementMeterPolicyResolver
    {
        return $this->auditResolver ??= new EntitlementMeterPolicyResolver;
    }

    protected function auditSignals(): RecordingEntitlementAuditSignals
    {
        return $this->auditSignalsFake ??= new RecordingEntitlementAuditSignals;
    }

    protected function expected(): InMemoryExpectedEntitlements
    {
        return new InMemoryExpectedEntitlements;
    }

    protected function makeEntitlementAudit(
        ?EntitlementMeterPolicyResolver $resolver = null,
        ?RecordingEntitlementAuditSignals $signals = null,
    ): DefaultEntitlementAudit {
        return new DefaultEntitlementAudit(
            $resolver ?? $this->grantedResolver(),
            $signals ?? $this->auditSignals(),
        );
    }

    /** The empty shipped source — proves deny-by-default "audit nothing until told". */
    protected function emptyExpected(): ExpectedEntitlements
    {
        return new InMemoryExpectedEntitlements;
    }
}
