<?php

declare(strict_types=1);

use Cbox\Billing\Metering\Exceptions\QuotaExceeded;

it('reserves within the leased allowance and hard-blocks beyond it', function (): void {
    $this->leaseSource()->grant('org_a', 'api.calls', 10);
    $enforcement = $this->makeEnforcement(refillSize: 100);

    $r = $enforcement->reserve('org_a', 'api.calls', 6);
    $enforcement->commit($r, 6);

    // 4 remain — a 6-unit reserve is refused (the org allowance is the hard limit).
    expect(fn () => $enforcement->reserve('org_a', 'api.calls', 6))->toThrow(QuotaExceeded::class);

    // The remaining 4 still fit; then it is exhausted.
    $enforcement->reserve('org_a', 'api.calls', 4);
    expect(fn () => $enforcement->reserve('org_a', 'api.calls', 1))->toThrow(QuotaExceeded::class);
});

it('returns unused units on commit and records the actual usage as a durable event', function (): void {
    $this->leaseSource()->grant('org_a', 'cpu.ms', 1_000);
    $enforcement = $this->makeEnforcement(refillSize: 1_000);

    $r = $enforcement->reserve('org_a', 'cpu.ms', 500);
    expect($enforcement->balance('org_a', 'cpu.ms'))->toBe(500);   // 1000 leased − 500 held

    $enforcement->commit($r, 200);                                  // used 200, 300 returned
    expect($enforcement->balance('org_a', 'cpu.ms'))->toBe(800);

    $events = $this->usageBuffer()->drain();
    expect($events)->toHaveCount(1)
        ->and($events[0]->value)->toBe(200)
        ->and($events[0]->meter)->toBe('cpu.ms')
        ->and($events[0]->service)->toBe('test-service')
        ->and($events[0]->occurredAt)->toBe(1_700_000_000_000);
});

it('releases a reservation back to the local lease with no usage recorded', function (): void {
    $this->leaseSource()->grant('org_a', 'jobs', 100);
    $enforcement = $this->makeEnforcement(refillSize: 100);

    $r = $enforcement->reserve('org_a', 'jobs', 40);
    expect($enforcement->balance('org_a', 'jobs'))->toBe(60);

    $enforcement->release($r);
    expect($enforcement->balance('org_a', 'jobs'))->toBe(100)
        ->and($this->usageBuffer()->size())->toBe(0);
});

it('never leases more than the organization allowance across independent nodes (no overspend)', function (): void {
    $this->leaseSource()->grant('org_a', 'events', 250);

    // Three independent nodes (each its own local store) leasing from one budget.
    $nodes = array_map(fn (): object => $this->makeEnforcement(refillSize: 100), range(1, 3));

    foreach ($nodes as $node) {
        try {
            while (true) {
                $node->reserve('org_a', 'events', 50);
            }
        } catch (QuotaExceeded) {
            // node drained its share of the central budget
        }
    }

    // The pessimistic-leasing invariant: the central budget can never over-grant.
    expect($this->leaseSource()->leasedOut('org_a', 'events'))->toBeLessThanOrEqual(250);
});

it('rejects a non-positive reservation and an over-commit', function (): void {
    $this->leaseSource()->grant('org_a', 'm', 100);
    $enforcement = $this->makeEnforcement();

    expect(fn () => $enforcement->reserve('org_a', 'm', 0))->toThrow(InvalidArgumentException::class);

    $r = $enforcement->reserve('org_a', 'm', 10);
    expect(fn () => $enforcement->commit($r, 11))->toThrow(InvalidArgumentException::class);
});
