<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\CreditConsumer;
use Cbox\Billing\Wallet\Enums\CreditType;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

$apiCalls = Denomination::unit('api.calls');

it('burns the soonest-expiring grant first (use-it-or-lose-it)', function () use ($apiCalls): void {
    $grants = [
        new CreditGrant('prepaid', 'org_a', CreditType::Prepaid, $apiCalls, 100, expiresAt: null, priority: 40, grantedAt: 1),
        new CreditGrant('promo', 'org_a', CreditType::Promotional, $apiCalls, 50, expiresAt: 2_000, priority: 10, grantedAt: 5),
    ];

    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 30, $grants, now: 1_000);

    expect($plan->draws)->toHaveCount(1)
        ->and($plan->draws[0]->grantId)->toBe('promo')
        ->and($plan->draws[0]->amount)->toBe(30)
        ->and($plan->isFullyCovered())->toBeTrue();
});

it('draws across multiple grants in order and reports the shortfall', function () use ($apiCalls): void {
    $grants = [
        new CreditGrant('g_expiring', 'org_a', CreditType::Promotional, $apiCalls, 20, expiresAt: 5_000, priority: 10, grantedAt: 1),
        new CreditGrant('g_prepaid', 'org_a', CreditType::Prepaid, $apiCalls, 15, expiresAt: null, priority: 40, grantedAt: 1),
    ];

    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 50, $grants, now: 1_000);

    expect($plan->draws)->toHaveCount(2)
        ->and($plan->draws[0]->grantId)->toBe('g_expiring')
        ->and($plan->draws[1]->grantId)->toBe('g_prepaid')
        ->and($plan->covered)->toBe(35)
        ->and($plan->shortfall)->toBe(15)
        ->and($plan->isFullyCovered())->toBeFalse();
});

it('ignores expired grants and other denominations', function () use ($apiCalls): void {
    $grants = [
        new CreditGrant('expired', 'org_a', CreditType::Promotional, $apiCalls, 100, expiresAt: 2_000, priority: 10, grantedAt: 1),
        new CreditGrant('other_meter', 'org_a', CreditType::Prepaid, Denomination::unit('cpu.ms'), 100, expiresAt: null, priority: 40, grantedAt: 1),
        new CreditGrant('money', 'org_a', CreditType::Prepaid, Denomination::money('EUR'), 100, expiresAt: null, priority: 40, grantedAt: 1),
        new CreditGrant('good', 'org_a', CreditType::Prepaid, $apiCalls, 40, expiresAt: null, priority: 40, grantedAt: 1),
    ];

    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 40, $grants, now: 3_000);

    expect($plan->draws)->toHaveCount(1)
        ->and($plan->draws[0]->grantId)->toBe('good')
        ->and($plan->isFullyCovered())->toBeTrue();
});

it('breaks ties by priority then age when expiry is equal', function () use ($apiCalls): void {
    $grants = [
        new CreditGrant('newer_low_prio', 'org_a', CreditType::Prepaid, $apiCalls, 10, expiresAt: null, priority: 40, grantedAt: 100),
        new CreditGrant('promo', 'org_a', CreditType::Promotional, $apiCalls, 10, expiresAt: null, priority: 10, grantedAt: 200),
        new CreditGrant('older_same_prio', 'org_a', CreditType::Prepaid, $apiCalls, 10, expiresAt: null, priority: 40, grantedAt: 1),
    ];

    $plan = (new CreditConsumer)->plan('org_a', $apiCalls, 30, $grants, now: 0);

    // promo (priority 10) first; then the two prepaid (priority 40) oldest-first.
    expect(array_map(fn ($d) => $d->grantId, $plan->draws))
        ->toBe(['promo', 'older_same_prio', 'newer_low_prio']);
});
