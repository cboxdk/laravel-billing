<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\CreditConsumer;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

$apiCalls = Denomination::unit('api.calls');
$order = Pools::defaultConsumptionOrder();

it('consumes spendable pools in the given order', function () use ($apiCalls, $order): void {
    $grants = [
        new CreditGrant('inc', 'org_a', Pools::included(), $apiCalls, 50, expiresAt: null, grantedAt: 1),
        new CreditGrant('promo', 'org_a', Pools::promotional(), $apiCalls, 30, expiresAt: 9_000, grantedAt: 1),
    ];

    // Order is [included, promotional, purchased] (ADR-0013): the per-period included
    // allowance burns before promotional credit.
    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 60, $grants, $order, now: 1_000);

    expect($plan->draws)->toHaveCount(2)
        ->and($plan->draws[0]->grantId)->toBe('inc')
        ->and($plan->draws[0]->amount)->toBe(50)
        ->and($plan->draws[0]->pool)->toBe(Pools::INCLUDED)
        ->and($plan->draws[1]->grantId)->toBe('promo')
        ->and($plan->draws[1]->amount)->toBe(10)
        ->and($plan->draws[1]->pool)->toBe(Pools::PROMOTIONAL)
        ->and($plan->isFullyCovered())->toBeTrue();
});

it('burns the soonest-expiring grant first within a pool', function () use ($apiCalls, $order): void {
    $grants = [
        new CreditGrant('later', 'org_a', Pools::promotional(), $apiCalls, 50, expiresAt: 9_000, grantedAt: 1),
        new CreditGrant('sooner', 'org_a', Pools::promotional(), $apiCalls, 50, expiresAt: 2_000, grantedAt: 5),
    ];

    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 30, $grants, $order, now: 1_000);

    expect($plan->draws)->toHaveCount(1)
        ->and($plan->draws[0]->grantId)->toBe('sooner')
        ->and($plan->draws[0]->amount)->toBe(30);
});

it('breaks ties by priority then age within a pool', function () use ($apiCalls, $order): void {
    $grants = [
        new CreditGrant('newer_low_prio', 'org_a', Pools::included(), $apiCalls, 10, expiresAt: null, priority: 40, grantedAt: 100),
        new CreditGrant('high_prio', 'org_a', Pools::included(), $apiCalls, 10, expiresAt: null, priority: 10, grantedAt: 200),
        new CreditGrant('older_same_prio', 'org_a', Pools::included(), $apiCalls, 10, expiresAt: null, priority: 40, grantedAt: 1),
    ];

    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 30, $grants, $order, now: 0);

    expect(array_map(fn ($d) => $d->grantId, $plan->draws))
        ->toBe(['high_prio', 'older_same_prio', 'newer_low_prio']);
});

it('never consumes a non-spendable pool and reports the shortfall', function () use ($apiCalls, $order): void {
    $grants = [
        new CreditGrant('reg', 'org_a', Pools::regulated(), $apiCalls, 100, expiresAt: 9_000, grantedAt: 1),
        new CreditGrant('inc', 'org_a', Pools::included(), $apiCalls, 20, expiresAt: null, grantedAt: 1),
    ];

    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 50, $grants, $order, now: 1_000);

    // Only the 20 included units are spendable; the regulated pool is untouched.
    expect($plan->covered)->toBe(20)
        ->and($plan->shortfall)->toBe(30)
        ->and($plan->draws)->toHaveCount(1)
        ->and($plan->draws[0]->grantId)->toBe('inc');
});

it('ignores expired grants and other denominations', function () use ($apiCalls, $order): void {
    $grants = [
        new CreditGrant('expired', 'org_a', Pools::promotional(), $apiCalls, 100, expiresAt: 2_000, grantedAt: 1),
        new CreditGrant('other_meter', 'org_a', Pools::included(), Denomination::unit('cpu.ms'), 100, expiresAt: null, grantedAt: 1),
        new CreditGrant('money', 'org_a', Pools::included(), Denomination::money('EUR'), 100, expiresAt: null, grantedAt: 1),
        new CreditGrant('good', 'org_a', Pools::included(), $apiCalls, 40, expiresAt: null, grantedAt: 1),
    ];

    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 40, $grants, $order, now: 3_000);

    expect($plan->draws)->toHaveCount(1)
        ->and($plan->draws[0]->grantId)->toBe('good')
        ->and($plan->isFullyCovered())->toBeTrue();
});

it('absorbs the uncovered remainder into the PAYG sink as a negative draw', function () use ($apiCalls, $order): void {
    $grants = [
        new CreditGrant('inc', 'org_a', Pools::included(), $apiCalls, 40, expiresAt: null, grantedAt: 1),
        new CreditGrant('topup', 'org_a', Pools::purchased(), $apiCalls, 20, expiresAt: null, grantedAt: 1),
    ];

    // Demand 100: 40 included + 20 purchased top-up spent, then 40 absorbed as debt
    // in the purchased sink (its grant ends at 20 − 20 − 40 = −40).
    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 100, $grants, $order, now: 1_000);

    expect($plan->isFullyCovered())->toBeTrue()
        ->and($plan->covered)->toBe(100)
        ->and($plan->shortfall)->toBe(0)
        ->and($plan->draws)->toHaveCount(2);

    $sink = collect($plan->draws)->firstWhere('pool', Pools::PURCHASED);
    expect($sink->grantId)->toBe('topup')
        ->and($sink->amount)->toBe(60);
});

it('absorbs overage against a zero-balance sink account', function () use ($apiCalls, $order): void {
    $grants = [
        new CreditGrant('overage', 'org_a', Pools::purchased(), $apiCalls, 0, expiresAt: null, grantedAt: 1),
    ];

    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 30, $grants, $order, now: 1_000);

    expect($plan->isFullyCovered())->toBeTrue()
        ->and($plan->draws)->toHaveCount(1)
        ->and($plan->draws[0]->grantId)->toBe('overage')
        ->and($plan->draws[0]->amount)->toBe(30);
});

it('reports a shortfall when the sink has no grant to carry the debt', function () use ($apiCalls, $order): void {
    $grants = [
        new CreditGrant('inc', 'org_a', Pools::included(), $apiCalls, 10, expiresAt: null, grantedAt: 1),
    ];

    // No purchased grant exists, so the PAYG sink cannot absorb the remainder.
    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 50, $grants, $order, now: 1_000);

    expect($plan->covered)->toBe(10)
        ->and($plan->shortfall)->toBe(40)
        ->and($plan->isFullyCovered())->toBeFalse();
});

it('leaves a shortfall when the last pool in the order may not go negative', function () use ($apiCalls): void {
    $grants = [
        new CreditGrant('inc', 'org_a', Pools::included(), $apiCalls, 15, expiresAt: null, grantedAt: 1),
    ];

    // Order ends on the (non-negative) included pool: no sink, remainder is shortfall.
    $order = [Pools::promotional(), Pools::included()];
    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 50, $grants, $order, now: 1_000);

    expect($plan->covered)->toBe(15)
        ->and($plan->shortfall)->toBe(35);
});
