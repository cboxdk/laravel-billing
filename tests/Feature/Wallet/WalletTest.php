<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Enums\CreditType;
use Cbox\Billing\Wallet\InMemoryWallet;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

it('consumes across grants and reflects the decremented balance, then reports a shortfall', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = new InMemoryWallet;
    $wallet->grant(new CreditGrant('promo', 'org_a', CreditType::Promotional, $calls, 30, expiresAt: 5_000, priority: 10, grantedAt: 1));
    $wallet->grant(new CreditGrant('prepaid', 'org_a', CreditType::Prepaid, $calls, 100, expiresAt: null, priority: 40, grantedAt: 1));

    expect($wallet->balance('org_a', $calls, now: 1_000))->toBe(130);

    $plan = $wallet->consume('org_a', $calls, 50, now: 1_000);
    expect($plan->isFullyCovered())->toBeTrue()
        ->and($wallet->balance('org_a', $calls, now: 1_000))->toBe(80);

    // Only 80 left — a 90 demand covers 80 and reports a 10 shortfall (→ overage).
    $plan2 = $wallet->consume('org_a', $calls, 90, now: 1_000);
    expect($plan2->covered)->toBe(80)
        ->and($plan2->shortfall)->toBe(10)
        ->and($wallet->balance('org_a', $calls, now: 1_000))->toBe(0);
});

it('excludes expired grants from balance and consumption', function (): void {
    $meter = Denomination::unit('m');
    $wallet = new InMemoryWallet;
    $wallet->grant(new CreditGrant('expired', 'org_a', CreditType::Promotional, $meter, 100, expiresAt: 2_000, priority: 10, grantedAt: 1));

    expect($wallet->balance('org_a', $meter, now: 3_000))->toBe(0)
        ->and($wallet->consume('org_a', $meter, 10, now: 3_000)->shortfall)->toBe(10);
});
