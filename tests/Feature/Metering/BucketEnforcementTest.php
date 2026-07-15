<?php

declare(strict_types=1);

use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\Exceptions\MeterNotEntitled;
use Cbox\Billing\Metering\Exceptions\QuotaExceeded;
use Cbox\Billing\Metering\ValueObjects\BucketRequest;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

it('checks entitlement FIRST — a disabled meter is refused before any allowance/cost math', function (): void {
    // Disabled but with headroom: a naive allowance-first path would compute zero
    // overage and run for free. Entitlement-first refuses it outright.
    $this->meterPolicies()->set('org_a', 'ai.tokens', MeterPolicy::disabled());
    $enforcement = $this->makeEnforcement();

    expect(fn () => $enforcement->reserveBuckets('org_a', [new BucketRequest('ai.tokens', 1)]))
        ->toThrow(MeterNotEntitled::class);

    // Nothing was metered — no allowance consumed, no usage buffered.
    expect($this->usageBuffer()->size())->toBe(0);
});

it('refuses an unknown meter by default (deny-by-default)', function (): void {
    $enforcement = $this->makeEnforcement();

    expect(fn () => $enforcement->reserveBuckets('org_a', [new BucketRequest('unregistered', 1)]))
        ->toThrow(MeterNotEntitled::class);
});

it('exempts included allowance and never invents phantom cost for an unlimited meter', function (): void {
    // Unlimited: multiplier is null, cost is zeroed explicitly, never blocked.
    $this->meterPolicies()->set('org_a', 'seats', MeterPolicy::unlimited());
    $enforcement = $this->makeEnforcement();

    $set = $enforcement->reserveBuckets('org_a', [new BucketRequest('seats', 1_000)]);

    $bucket = $set->bucket('seats');
    expect($bucket)->not->toBeNull()
        ->and($bucket->exempt)->toBe(1_000)     // unlimited exempts every unit...
        ->and($bucket->billable)->toBe(0)       // ...so nothing is billable...
        ->and($bucket->estimatedCost())->toBe(0.0) // ...and cost is zeroed explicitly (null multiplier, no phantom 1.0)
        ->and($set->estimatedCost())->toBe(0.0);

    $enforcement->commitBuckets($set, ['seats' => 1_000]);
    expect($this->usageBuffer()->drain()[0]->value)->toBe(1_000);
});

it('claims disjoint allowance slices so included units are exempt exactly once', function (): void {
    // allowance 10, Bill overage at x2. Two 6-unit reserves span the boundary.
    $this->meterPolicies()->set('org_a', 'api.calls', MeterPolicy::metered(10, 2.0, OverageBehaviour::Bill));
    $this->leaseSource()->grant('org_a', 'api.calls', 1_000);
    $enforcement = $this->makeEnforcement();

    $first = $enforcement->reserveBuckets('org_a', [new BucketRequest('api.calls', 6)]);
    $second = $enforcement->reserveBuckets('org_a', [new BucketRequest('api.calls', 6)]);

    // First slice [0,6): all exempt. Second slice [6,12): 4 exempt, 2 overage.
    expect($first->bucket('api.calls')->exempt)->toBe(6)
        ->and($first->bucket('api.calls')->billable)->toBe(0)
        ->and($second->bucket('api.calls')->sliceStart)->toBe(6)
        ->and($second->bucket('api.calls')->exempt)->toBe(4)
        ->and($second->bucket('api.calls')->billable)->toBe(2)
        ->and($second->estimatedCost())->toBe(4.0); // 2 overage x 2.0
});

it('keeps each meter\'s allowance isolated — consuming one never touches another', function (): void {
    $this->meterPolicies()
        ->set('org_a', 'meter.a', MeterPolicy::metered(10, 1.0, OverageBehaviour::Bill))
        ->set('org_a', 'meter.b', MeterPolicy::metered(10, 1.0, OverageBehaviour::Bill));
    $this->leaseSource()->grant('org_a', 'meter.a', 1_000)->grant('org_a', 'meter.b', 1_000);
    $enforcement = $this->makeEnforcement();

    // Exhaust meter.a's whole allowance (and then some overage).
    $enforcement->reserveBuckets('org_a', [new BucketRequest('meter.a', 15)]);

    // meter.b still has its full, untouched isolated allowance.
    $set = $enforcement->reserveBuckets('org_a', [new BucketRequest('meter.b', 10)]);
    expect($set->bucket('meter.b')->exempt)->toBe(10)
        ->and($set->bucket('meter.b')->billable)->toBe(0);
});

it('reserves several buckets at once and totals cost as the sum of per-bucket weighted usage', function (): void {
    $this->meterPolicies()
        ->set('org_a', 'base.op', MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill))
        ->set('org_a', 'field.append', MeterPolicy::metered(0, 0.5, OverageBehaviour::Bill))
        ->set('org_a', 'heavy.op', MeterPolicy::metered(0, 4.0, OverageBehaviour::Bill));
    foreach (['base.op', 'field.append', 'heavy.op'] as $m) {
        $this->leaseSource()->grant('org_a', $m, 1_000);
    }
    $enforcement = $this->makeEnforcement();

    $set = $enforcement->reserveBuckets('org_a', [
        new BucketRequest('base.op', 2),      // 2 * 1.0 = 2.0
        new BucketRequest('field.append', 10), // 10 * 0.5 = 5.0
        new BucketRequest('heavy.op', 3),      // 3 * 4.0 = 12.0
    ]);

    expect($set->estimatedCost())->toBe(19.0)
        ->and($set->buckets)->toHaveCount(3);
});

it('blocks overage at the allowance boundary under Block behaviour', function (): void {
    $this->meterPolicies()->set('org_a', 'jobs', MeterPolicy::metered(5, 1.0, OverageBehaviour::Block));
    $enforcement = $this->makeEnforcement();

    // 5 within allowance is fine.
    $enforcement->reserveBuckets('org_a', [new BucketRequest('jobs', 5)]);

    // The 6th unit is overage under a hard block → refused, allowance rolled back.
    expect(fn () => $enforcement->reserveBuckets('org_a', [new BucketRequest('jobs', 1)]))
        ->toThrow(QuotaExceeded::class);
});

it('bills overage against the leased paid budget as a hard spend cap', function (): void {
    // allowance 0 → everything is overage; only 3 paid units are leasable.
    $this->meterPolicies()->set('org_a', 'render.ms', MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill));
    $this->leaseSource()->grant('org_a', 'render.ms', 3);
    $enforcement = $this->makeEnforcement(refillSize: 3);

    $enforcement->reserveBuckets('org_a', [new BucketRequest('render.ms', 3)]);

    // Paid budget exhausted → the next overage unit is a hard refusal.
    expect(fn () => $enforcement->reserveBuckets('org_a', [new BucketRequest('render.ms', 1)]))
        ->toThrow(QuotaExceeded::class);
});

it('is all-or-nothing — a refused bucket rolls back the ones already claimed', function (): void {
    $this->meterPolicies()
        ->set('org_a', 'ok.meter', MeterPolicy::metered(100, 1.0, OverageBehaviour::Bill))
        ->set('org_a', 'blocked.meter', MeterPolicy::disabled());
    $this->leaseSource()->grant('org_a', 'ok.meter', 1_000);
    $enforcement = $this->makeEnforcement();

    expect(fn () => $enforcement->reserveBuckets('org_a', [
        new BucketRequest('ok.meter', 10),
        new BucketRequest('blocked.meter', 1),
    ]))->toThrow(MeterNotEntitled::class);

    // ok.meter's slice was rolled back — its full allowance is available again.
    $set = $enforcement->reserveBuckets('org_a', [new BucketRequest('ok.meter', 100)]);
    expect($set->bucket('ok.meter')->exempt)->toBe(100)
        ->and($set->bucket('ok.meter')->billable)->toBe(0);
});

it('commits actual usage per bucket, returns the unused tail, and records a durable event each', function (): void {
    $this->meterPolicies()
        ->set('org_a', 'a', MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill))
        ->set('org_a', 'b', MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill));
    $this->leaseSource()->grant('org_a', 'a', 100)->grant('org_a', 'b', 100);
    $enforcement = $this->makeEnforcement(refillSize: 100);

    $set = $enforcement->reserveBuckets('org_a', [new BucketRequest('a', 10), new BucketRequest('b', 10)]);

    // 10 paid units held on each meter.
    expect($enforcement->balance('org_a', 'a'))->toBe(90);

    $enforcement->commitBuckets($set, ['a' => 4, 'b' => 0]);

    // Unused overage returned to the lease: a used 4 (6 back → 96), b used 0 (all back → 100).
    expect($enforcement->balance('org_a', 'a'))->toBe(96)
        ->and($enforcement->balance('org_a', 'b'))->toBe(100);

    // One durable event, only for the meter with non-zero usage.
    $events = $this->usageBuffer()->drain();
    expect($events)->toHaveCount(1)
        ->and($events[0]->meter)->toBe('a')
        ->and($events[0]->value)->toBe(4);
});

it('releases a reserved set without charging', function (): void {
    $this->meterPolicies()->set('org_a', 'm', MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill));
    $this->leaseSource()->grant('org_a', 'm', 100);
    $enforcement = $this->makeEnforcement(refillSize: 100);

    $set = $enforcement->reserveBuckets('org_a', [new BucketRequest('m', 40)]);
    expect($enforcement->balance('org_a', 'm'))->toBe(60);

    $enforcement->releaseBuckets($set);
    expect($enforcement->balance('org_a', 'm'))->toBe(100)
        ->and($this->usageBuffer()->size())->toBe(0);
});
