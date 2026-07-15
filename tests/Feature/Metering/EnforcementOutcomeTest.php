<?php

declare(strict_types=1);

use Cbox\Billing\Metering\Enums\DenialReason;
use Cbox\Billing\Metering\Enums\InfraFailurePolicy;
use Cbox\Billing\Metering\Enums\OutcomeStatus;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\Exceptions\QuotaExceeded;
use Cbox\Billing\Metering\Testing\OutageLocalStore;
use Cbox\Billing\Metering\ValueObjects\BucketRequest;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Cbox\Billing\Metering\ValueObjects\Reservation;
use Cbox\Billing\Metering\ValueObjects\ReservationSet;

/*
 * ADR-0004 — enforcement fails OPEN on infrastructure, CLOSED on semantics.
 * The outcome-returning surface makes which path fired observable.
 */

it('returns an Allowed outcome carrying the reservation on a reached decision', function (): void {
    $this->leaseSource()->grant('org_a', 'api.calls', 100);
    $enforcement = $this->makeEnforcement();

    $outcome = $enforcement->reserveOutcome('org_a', 'api.calls', 10);

    expect($outcome->status)->toBe(OutcomeStatus::Allowed)
        ->and($outcome->admitted())->toBeTrue()
        ->and($outcome->refused())->toBeFalse()
        ->and($outcome->failedOpen())->toBeFalse()
        ->and($outcome->reason)->toBeNull()
        ->and($outcome->fault)->toBeNull()
        ->and($outcome->reservation())->toBeInstanceOf(Reservation::class)
        ->and($outcome->reservation()->amount)->toBe(10)
        ->and($outcome->meters)->toBe(['api.calls']);

    // No infra fault occurred → nothing signalled.
    expect($this->enforcementSignals()->indeterminateSignals())->toBe([]);
});

it('fails CLOSED to Denied(QuotaExhausted) when the allowance is exhausted (single meter)', function (): void {
    $this->leaseSource()->grant('org_a', 'api.calls', 5);
    $enforcement = $this->makeEnforcement();

    $outcome = $enforcement->reserveOutcome('org_a', 'api.calls', 6);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->reason)->toBe(DenialReason::QuotaExhausted)
        ->and($outcome->admitted())->toBeFalse()
        ->and($outcome->refused())->toBeTrue();
});

it('fails CLOSED to Denied(UnknownMeter) for an unregistered meter (deny-by-default)', function (): void {
    $enforcement = $this->makeEnforcement();

    $outcome = $enforcement->reserveBucketsOutcome('org_a', [new BucketRequest('unregistered', 1)]);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->reason)->toBe(DenialReason::UnknownMeter)
        ->and($outcome->refused())->toBeTrue();
});

it('fails CLOSED to Denied(DisabledMeter) for a disabled meter', function (): void {
    $this->meterPolicies()->set('org_a', 'ai.tokens', MeterPolicy::disabled());
    $enforcement = $this->makeEnforcement();

    $outcome = $enforcement->reserveBucketsOutcome('org_a', [new BucketRequest('ai.tokens', 1)]);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->reason)->toBe(DenialReason::DisabledMeter);
});

it('fails CLOSED to Denied(QuotaExhausted) at the Block boundary (bucket path)', function (): void {
    $this->meterPolicies()->set('org_a', 'jobs', MeterPolicy::metered(5, 1.0, OverageBehaviour::Block));
    $enforcement = $this->makeEnforcement();

    $ok = $enforcement->reserveBucketsOutcome('org_a', [new BucketRequest('jobs', 5)]);
    expect($ok->status)->toBe(OutcomeStatus::Allowed);

    $over = $enforcement->reserveBucketsOutcome('org_a', [new BucketRequest('jobs', 1)]);
    expect($over->status)->toBe(OutcomeStatus::Denied)
        ->and($over->reason)->toBe(DenialReason::QuotaExhausted);
});

it('returns an Allowed outcome carrying the reserved set on the bucket path', function (): void {
    $this->meterPolicies()
        ->set('org_a', 'a', MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill))
        ->set('org_a', 'b', MeterPolicy::metered(0, 2.0, OverageBehaviour::Bill));
    $this->leaseSource()->grant('org_a', 'a', 100)->grant('org_a', 'b', 100);
    $enforcement = $this->makeEnforcement();

    $outcome = $enforcement->reserveBucketsOutcome('org_a', [
        new BucketRequest('a', 3),
        new BucketRequest('b', 4),
    ]);

    expect($outcome->status)->toBe(OutcomeStatus::Allowed)
        ->and($outcome->meters)->toBe(['a', 'b'])
        ->and($outcome->reservationSet())->toBeInstanceOf(ReservationSet::class)
        ->and($outcome->reservationSet()->estimatedCost())->toBe(11.0); // 3*1 + 4*2

    // The reserved set still commits through the throw-based path unchanged.
    $enforcement->commitBuckets($outcome->reservationSet(), ['a' => 3, 'b' => 4]);
    expect($this->usageBuffer()->drain())->toHaveCount(2);
});

it('fails OPEN on an infrastructure outage: Indeterminate is ADMITTED and signalled (default policy)', function (): void {
    $enforcement = $this->makeEnforcement(store: new OutageLocalStore); // default InfraFailurePolicy::Allow

    $outcome = $enforcement->reserveOutcome('org_a', 'api.calls', 10);

    expect($outcome->status)->toBe(OutcomeStatus::Indeterminate)
        ->and($outcome->admitted())->toBeTrue()          // fail-open: paid traffic is not throttled by a blip
        ->and($outcome->refused())->toBeFalse()
        ->and($outcome->failedOpen())->toBeTrue()
        ->and($outcome->resolvedBy)->toBe(InfraFailurePolicy::Allow)
        ->and($outcome->fault)->not->toBeNull()
        ->and($outcome->fault->reason)->toContain('simulated outage');

    // The fail-open was signalled so operators can see it.
    $signals = $this->enforcementSignals()->indeterminateSignals();
    expect($signals)->toHaveCount(1)
        ->and($signals[0]->failedOpen())->toBeTrue()
        ->and($this->enforcementSignals()->failedOpenCount())->toBe(1);
});

it('fails CLOSED on an infrastructure outage for strict tenants: Indeterminate is REFUSED and signalled', function (): void {
    $enforcement = $this->makeEnforcement(
        store: new OutageLocalStore,
        infraPolicy: InfraFailurePolicy::Deny,
    );

    $outcome = $enforcement->reserveOutcome('org_a', 'api.calls', 10);

    expect($outcome->status)->toBe(OutcomeStatus::Indeterminate)
        ->and($outcome->admitted())->toBeFalse()         // strict knob: refuse rather than admit un-metered usage
        ->and($outcome->refused())->toBeTrue()
        ->and($outcome->failedOpen())->toBeFalse()
        ->and($outcome->resolvedBy)->toBe(InfraFailurePolicy::Deny);

    // Still signalled — a strict deny during an outage is an operator-visible event.
    expect($this->enforcementSignals()->indeterminateSignals())->toHaveCount(1)
        ->and($this->enforcementSignals()->failedOpenCount())->toBe(0);
});

it('classifies a mid-set dependency outage as Indeterminate, not a semantic Denial (bucket path)', function (): void {
    // The meter IS entitled — so a refusal here can only be infrastructure, never
    // deny-by-default. The store is down, so the allowance claim cannot be evaluated.
    $this->meterPolicies()->set('org_a', 'render.ms', MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill));
    $enforcement = $this->makeEnforcement(store: new OutageLocalStore);

    $outcome = $enforcement->reserveBucketsOutcome('org_a', [new BucketRequest('render.ms', 5)]);

    expect($outcome->status)->toBe(OutcomeStatus::Indeterminate)
        ->and($outcome->admitted())->toBeTrue()
        ->and($outcome->meters)->toBe(['render.ms']);
});

it('does NOT swallow a caller bug into an outcome — a non-positive estimate still throws', function (): void {
    $this->leaseSource()->grant('org_a', 'api.calls', 100);
    $enforcement = $this->makeEnforcement();

    expect(fn () => $enforcement->reserveOutcome('org_a', 'api.calls', 0))
        ->toThrow(InvalidArgumentException::class);

    // A programmer error is neither a decision nor an outage — nothing was signalled.
    expect($this->enforcementSignals()->indeterminateSignals())->toBe([]);
});

it('keeps the throw-based path intact alongside the outcome path (back-compat)', function (): void {
    $this->leaseSource()->grant('org_a', 'api.calls', 5);
    $enforcement = $this->makeEnforcement();

    // Throw-based reserve still throws on exhaustion...
    expect(fn () => $enforcement->reserve('org_a', 'api.calls', 6))
        ->toThrow(QuotaExceeded::class);

    // ...while the outcome path over the same enforcer reports the same fact as data.
    $outcome = $enforcement->reserveOutcome('org_a', 'api.calls', 6);
    expect($outcome->reason)->toBe(DenialReason::QuotaExhausted);
});
