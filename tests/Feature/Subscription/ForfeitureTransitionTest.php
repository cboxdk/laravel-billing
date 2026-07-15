<?php

declare(strict_types=1);

use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\SubscriptionTransition;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

beforeEach(function (): void {
    $this->manager = new SubscriptionManager;
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $this->nextPeriod = new BillingPeriod(new DateTimeImmutable('2025-10-01'), new DateTimeImmutable('2025-11-01'));
    $this->sub = $this->manager->create('sub_1', 'org_a', 'pro', 'pro-v1', $this->period);
});

it('classifies transitions: cancel and downgrade-to-no-plan leave without landing; start and switch land', function (): void {
    $active = $this->sub;
    $another = $this->manager->create('sub_2', 'org_a', 'team', 'team-v1', $this->nextPeriod);
    $ended = $this->manager->cancelNow($active);

    expect(SubscriptionTransition::started($active)->leftWithoutLanding())->toBeFalse()
        ->and(SubscriptionTransition::switched($active, $another)->leftWithoutLanding())->toBeFalse()
        ->and(SubscriptionTransition::canceled($active)->leftWithoutLanding())->toBeTrue()
        ->and(SubscriptionTransition::between($active, null)->leftWithoutLanding())->toBeTrue()
        ->and(SubscriptionTransition::between($active, $ended)->leftWithoutLanding())->toBeTrue();
});

it('forfeits on an immediate cancel-to-null through the real wallet', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 100, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('topup', 'org_a', Pools::purchased(), $calls, 20, expiresAt: null, grantedAt: 1));

    $lifecycle = $this->lifecycleForfeitingInto($wallet);
    $outcome = $lifecycle->cancelNow($this->sub, now: 1_000);

    expect($outcome->subscription?->isActive())->toBeFalse()
        ->and($outcome->forfeited->total())->toBe(100)
        ->and($wallet->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(0)
        ->and($wallet->balance('org_a', Pools::purchased(), $calls, now: 1_000))->toBe(20);
});

it('forfeits when a renewal enacts a due cancellation, but not on an ordinary renewal', function (): void {
    // Ordinary renewal: still active, nothing forfeited.
    $renewed = $this->lifecycleWith($this->fakeForfeiture())->renew($this->sub, $this->nextPeriod, now: 1_000);
    expect($renewed->subscription?->isActive())->toBeTrue()
        ->and($this->fakeForfeiture()->forfeited('org_a'))->toBeFalse();

    // Cancel-at-period-end, then renew into the cancellation: left without landing.
    $canceling = $this->manager->cancelAtPeriodEnd($this->sub);
    $ended = $this->lifecycleWith($this->fakeForfeiture())->renew($canceling, $this->nextPeriod, now: 2_000);

    expect($ended->subscription?->isActive())->toBeFalse()
        ->and($this->fakeForfeiture()->forfeited('org_a'))->toBeTrue();
});

it('does not forfeit when switching onto another active subscription', function (): void {
    $to = $this->manager->create('sub_2', 'org_a', 'team', 'team-v1', $this->nextPeriod);

    $outcome = $this->lifecycleWith($this->fakeForfeiture())->switchTo($this->sub, $to, now: 1_000);

    expect($outcome->forfeited->isEmpty())->toBeTrue()
        ->and($this->fakeForfeiture()->forfeited('org_a'))->toBeFalse();
});
