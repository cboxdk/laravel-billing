<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Enums\RemovalReason;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

it('expires only the aged-out lot, leaving a younger lot in the same pool untouched', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();

    // Two promotional lots in the SAME pool: an older one that ages out at 2_000 and a
    // younger one still live well past the sweep.
    $wallet->grant(new CreditGrant('older', 'org_a', Pools::promotional(), $calls, 40, expiresAt: 2_000, grantedAt: 1));
    $wallet->grant(new CreditGrant('younger', 'org_a', Pools::promotional(), $calls, 70, expiresAt: 9_000, grantedAt: 2));

    $report = $wallet->expire('org_a', now: 3_000);

    // The older lot's 40 is removed; the younger lot's 70 is fully intact — no over-expiry.
    expect($report->total())->toBe(40)
        ->and($report->count())->toBe(1)
        ->and($report->removals[0]->grantId)->toBe('older')
        ->and($report->removals[0]->reason)->toBe(RemovalReason::Expired)
        ->and($wallet->balance('org_a', Pools::promotional(), $calls, now: 3_000))->toBe(70);
});

it('does not touch a mayGoNegative sink’s debt when sweeping', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();

    // The purchased sink carries debt and never expires; an expired promo lot sits alongside.
    $wallet->grant(new CreditGrant('debt', 'org_a', Pools::purchased(), $calls, -50, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('promo', 'org_a', Pools::promotional(), $calls, 30, expiresAt: 2_000, grantedAt: 1));

    $report = $wallet->expire('org_a', now: 3_000);

    expect($report->total())->toBe(30)
        ->and($wallet->balance('org_a', Pools::purchased(), $calls, now: 3_000))->toBe(-50)
        ->and($wallet->balance('org_a', Pools::promotional(), $calls, now: 3_000))->toBe(0);
});

it('is idempotent: a second sweep removes nothing', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('promo', 'org_a', Pools::promotional(), $calls, 30, expiresAt: 2_000, grantedAt: 1));

    expect($wallet->expire('org_a', now: 3_000)->total())->toBe(30)
        ->and($wallet->expire('org_a', now: 3_000)->isEmpty())->toBeTrue();
});

it('bounds the sweep to the look-back window', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();

    // 'ancient' aged out long ago (before the window); 'recent' aged out within it.
    $wallet->grant(new CreditGrant('ancient', 'org_a', Pools::promotional(), $calls, 40, expiresAt: 1_000, grantedAt: 1));
    $wallet->grant(new CreditGrant('recent', 'org_a', Pools::promotional(), $calls, 25, expiresAt: 9_000, grantedAt: 2));

    // Window (10_000 - 5_000, 10_000] = (5_000, 10_000]: only 'recent' is in range.
    $report = $wallet->expire('org_a', now: 10_000, lookback: 5_000);

    expect($report->count())->toBe(1)
        ->and($report->removals[0]->grantId)->toBe('recent')
        ->and($report->total())->toBe(25);
});

it('leaves a still-live lot alone until it actually expires', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('promo', 'org_a', Pools::promotional(), $calls, 30, expiresAt: 5_000, grantedAt: 1));

    // Swept before expiry: nothing removed, balance intact.
    expect($wallet->expire('org_a', now: 4_000)->isEmpty())->toBeTrue()
        ->and($wallet->balance('org_a', Pools::promotional(), $calls, now: 4_000))->toBe(30);
});
