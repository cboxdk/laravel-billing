<?php

declare(strict_types=1);

use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAudit;
use Cbox\Billing\Entitlement\Audit\Enums\EntitlementOutageKind;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditTarget;
use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

it('reports no finding when every expected key resolves live', function (): void {
    $resolver = $this->grantedResolver();
    $resolver->grant('org_a', 'api.calls', MeterPolicy::unlimited());
    $resolver->grant('org_a', 'seats', MeterPolicy::metered(10, 0.0, OverageBehaviour::Block));

    $expected = $this->expected()->expect('org_a', 'pro', ['api.calls', 'seats']);

    $report = $this->makeEntitlementAudit()->audit($expected->targets());

    expect($report->isClean())->toBeTrue()
        ->and($report->hasOutage())->toBeFalse()
        ->and($report->targetsAudited)->toBe(1)
        ->and($this->auditSignals()->outageCount())->toBe(0);
});

it('flags a missing expected key as an outage finding and emits the signal', function (): void {
    // Bad rollout: the 'seats' row was never written for org_a, so it resolves dark.
    $resolver = $this->grantedResolver();
    $resolver->grant('org_a', 'api.calls', MeterPolicy::unlimited());

    $expected = $this->expected()->expect('org_a', 'pro', ['api.calls', 'seats']);
    $signals = $this->auditSignals();

    $report = $this->makeEntitlementAudit($resolver, $signals)->audit($expected->targets());

    expect($report->hasOutage())->toBeTrue()
        ->and($report->findings)->toHaveCount(1);

    $finding = $report->findings[0];
    expect($finding->org)->toBe('org_a')
        ->and($finding->plan)->toBe('pro')
        ->and($finding->missingKeys)->toBe(['seats'])
        ->and($finding->resolvedKeys)->toBe(['api.calls'])
        ->and($finding->isAllDisabled())->toBeFalse()
        ->and($finding->kind())->toBe(EntitlementOutageKind::MissingExpected);

    // The outage signal fired with the same finding.
    expect($signals->outageCount())->toBe(1)
        ->and($signals->outageSignals()[0]->missingKeys)->toBe(['seats']);
});

it('flags all-disabled (every expected key missing) as the loudest outage kind', function (): void {
    // Whole plan's rows are absent: deny-by-default => the org resolves to all-disabled.
    $expected = $this->expected()->expect('org_a', 'pro', ['api.calls', 'seats']);
    $signals = $this->auditSignals();

    $report = $this->makeEntitlementAudit($this->grantedResolver(), $signals)->audit($expected->targets());

    $finding = $report->findings[0];
    expect($finding->isAllDisabled())->toBeTrue()
        ->and($finding->kind())->toBe(EntitlementOutageKind::AllDisabled)
        ->and($finding->resolvedKeys)->toBe([])
        ->and($finding->missingKeys)->toBe(['api.calls', 'seats'])
        ->and($report->totalOutages())->toHaveCount(1)
        ->and($signals->totalOutageCount())->toBe(1);
});

it('treats a present-but-disabled policy as dark, not live', function (): void {
    $resolver = $this->grantedResolver();
    $resolver->grant('org_a', 'api.calls', MeterPolicy::disabled());

    $expected = $this->expected()->expect('org_a', 'pro', ['api.calls']);

    $report = $this->makeEntitlementAudit($resolver)->audit($expected->targets());

    expect($report->hasOutage())->toBeTrue()
        ->and($report->findings[0]->missingKeys)->toBe(['api.calls']);
});

it('demonstrates the rollout/drift signature is blind to a missing row', function (): void {
    // A drift signature is derived from the rows that EXIST. Model it as a digest over
    // the keys the rows carry — the signature can only see what is present.
    $signature = function (array $rows): string {
        /** @var array<string, list<string>> $rows */
        $live = [];
        foreach ($rows as $org => $keys) {
            foreach ($keys as $key) {
                $live[] = $org.':'.$key;
            }
        }
        sort($live);

        return md5((string) json_encode($live));
    };

    // Outage org: on 'pro', SHOULD resolve ['api.calls','seats'], but the 'seats' row
    // was dropped by a bad rollout — only 'api.calls' exists.
    $outage = $this->grantedResolver();
    $outage->grant('org_a', 'api.calls', MeterPolicy::unlimited());

    // Baseline org: legitimately on 'starter', only ever entitled to ['api.calls'].
    $baseline = new EntitlementMeterPolicyResolver;
    $baseline->grant('org_a', 'api.calls', MeterPolicy::unlimited());

    // The signature sees only the rows that exist — identical in both — so it CANNOT
    // tell the outage org from the healthy one. A missing row is invisible to it.
    expect($signature(['org_a' => ['api.calls']]))
        ->toBe($signature(['org_a' => ['api.calls']]));

    // The audit, using the INDEPENDENT expected set (not the rows), catches what the
    // signature cannot: 'pro' expects 'seats' and it is dark => outage; 'starter' does
    // not expect it => clean.
    $outageReport = $this->makeEntitlementAudit($outage)
        ->audit($this->expected()->expect('org_a', 'pro', ['api.calls', 'seats'])->targets());

    $baselineReport = $this->makeEntitlementAudit($baseline, $this->auditSignals())
        ->audit($this->expected()->expect('org_a', 'starter', ['api.calls'])->targets());

    expect($outageReport->hasOutage())->toBeTrue()
        ->and($outageReport->findings[0]->missingKeys)->toBe(['seats'])
        ->and($baselineReport->isClean())->toBeTrue();
});

it('audits many targets and reports only the ones with gaps', function (): void {
    $resolver = $this->grantedResolver();
    $resolver->grant('org_ok', 'api.calls', MeterPolicy::unlimited());
    // org_bad has no rows at all.

    $expected = $this->expected()
        ->expect('org_ok', 'pro', ['api.calls'])
        ->expect('org_bad', 'pro', ['api.calls']);

    $report = $this->makeEntitlementAudit($resolver)->audit($expected->targets());

    expect($report->targetsAudited)->toBe(2)
        ->and($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->org)->toBe('org_bad');
});

it('audits nothing with the empty deny-by-default source', function (): void {
    $report = $this->makeEntitlementAudit()->audit($this->emptyExpected()->targets());

    expect($report->isClean())->toBeTrue()
        ->and($report->targetsAudited)->toBe(0);
});

it('wires the audit through the container against the bound resolver', function (): void {
    $audit = app(EntitlementAudit::class);

    // Container resolver has no grants => every expected key is dark.
    $target = new AuditTarget('org_a', 'pro', ['api.calls']);
    $report = $audit->audit([$target]);

    expect($report->hasOutage())->toBeTrue()
        ->and($report->findings[0]->isAllDisabled())->toBeTrue();
});
