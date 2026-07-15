<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Enums\RemovalReason;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

it('forfeits only forfeitsOnCancel pools, keeping promotional and purchased', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 100, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('promo', 'org_a', Pools::promotional(), $calls, 30, expiresAt: 9_000, grantedAt: 1));
    $wallet->grant(new CreditGrant('topup', 'org_a', Pools::purchased(), $calls, 20, expiresAt: null, grantedAt: 1));

    $report = $wallet->forfeit('org_a', now: 1_000);

    expect($report->total())->toBe(100)
        ->and($report->count())->toBe(1)
        ->and($report->removals[0]->grantId)->toBe('inc')
        ->and($report->removals[0]->reason)->toBe(RemovalReason::Forfeited)
        ->and($wallet->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(0)
        ->and($wallet->balance('org_a', Pools::promotional(), $calls, now: 1_000))->toBe(30)
        ->and($wallet->balance('org_a', Pools::purchased(), $calls, now: 1_000))->toBe(20);
});

it('floors at zero so a negative pay-as-you-go pool cannot offset the forfeited allotment', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    // Forfeitable allotment of 100 alongside 50 of accrued PAYG debt.
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 100, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('debt', 'org_a', Pools::purchased(), $calls, -50, expiresAt: null, grantedAt: 1));

    $report = $wallet->forfeit('org_a', now: 1_000);

    // The whole 100 is forfeited (not netted to 50 against the debt); the −50 debt stands.
    expect($report->total())->toBe(100)
        ->and($wallet->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(0)
        ->and($wallet->balance('org_a', Pools::purchased(), $calls, now: 1_000))->toBe(-50);
});

it('is idempotent: a second forfeiture removes nothing', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 100, expiresAt: null, grantedAt: 1));

    expect($wallet->forfeit('org_a', now: 1_000)->total())->toBe(100)
        ->and($wallet->forfeit('org_a', now: 1_000)->isEmpty())->toBeTrue();
});

it('only forfeits the named org', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('a', 'org_a', Pools::included(), $calls, 100, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('b', 'org_b', Pools::included(), $calls, 80, expiresAt: null, grantedAt: 1));

    $wallet->forfeit('org_a', now: 1_000);

    expect($wallet->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(0)
        ->and($wallet->balance('org_b', Pools::included(), $calls, now: 1_000))->toBe(80);
});
