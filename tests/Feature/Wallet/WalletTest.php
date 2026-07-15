<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Exceptions\InvalidGrant;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

it('consumes across pools and reflects the per-pool balances', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('promo', 'org_a', Pools::promotional(), $calls, 30, expiresAt: 5_000, grantedAt: 1));
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 100, expiresAt: null, grantedAt: 1));

    expect($wallet->balance('org_a', Pools::promotional(), $calls, now: 1_000))->toBe(30)
        ->and($wallet->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(100);

    // 50 demand, order [included, promotional, purchased] (ADR-0013): 50 from the
    // included allowance, promotional credit untouched.
    $plan = $wallet->consume('org_a', $calls, 50, $this->consumptionOrder(), now: 1_000);
    expect($plan->isFullyCovered())->toBeTrue()
        ->and($wallet->balance('org_a', Pools::promotional(), $calls, now: 1_000))->toBe(30)
        ->and($wallet->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(50);
});

it('drives the purchased pool negative as the PAYG sink', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 40, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('topup', 'org_a', Pools::purchased(), $calls, 10, expiresAt: null, grantedAt: 1));

    // 100 demand: 40 included + 10 top-up, then 50 accrued as debt in purchased.
    $plan = $wallet->consume('org_a', $calls, 100, $this->consumptionOrder(), now: 1_000);

    expect($plan->isFullyCovered())->toBeTrue()
        ->and($wallet->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(0)
        ->and($wallet->balance('org_a', Pools::purchased(), $calls, now: 1_000))->toBe(-50);
});

it('reports a shortfall when nothing can cover the demand', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 20, expiresAt: null, grantedAt: 1));

    // Only 20 included, no PAYG sink provisioned: a 30 demand reports a 10 shortfall.
    $plan = $wallet->consume('org_a', $calls, 30, $this->consumptionOrder(), now: 1_000);
    expect($plan->covered)->toBe(20)
        ->and($plan->shortfall)->toBe(10)
        ->and($wallet->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(0);
});

it('rejects a grant into a pool that requires an expiry', function (): void {
    $calls = Denomination::unit('api.calls');

    expect(fn () => new CreditGrant('reg', 'org_a', Pools::regulated(), $calls, 100, expiresAt: null))
        ->toThrow(InvalidGrant::class);
});

it('excludes expired grants from balance and consumption', function (): void {
    $meter = Denomination::unit('m');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('expired', 'org_a', Pools::promotional(), $meter, 100, expiresAt: 2_000, grantedAt: 1));

    expect($wallet->balance('org_a', Pools::promotional(), $meter, now: 3_000))->toBe(0)
        ->and($wallet->consume('org_a', $meter, 10, $this->consumptionOrder(), now: 3_000)->shortfall)->toBe(10);
});
