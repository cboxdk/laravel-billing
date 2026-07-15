<?php

declare(strict_types=1);

use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\PlanSwitchConsequence;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

beforeEach(function (): void {
    $this->manager = new SubscriptionManager;
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $this->nextPeriod = new BillingPeriod(new DateTimeImmutable('2025-10-01'), new DateTimeImmutable('2025-11-01'));
    $this->calls = Denomination::unit('api.calls');
    $this->from = $this->manager->create('sub_1', 'org_a', 'pro', 'pro-v1', $this->period);
    $this->to = $this->manager->create('sub_2', 'org_a', 'team', 'team-v1', $this->nextPeriod);
});

function incomingAllotment(int $units, Denomination $calls): CreditGrant
{
    return new CreditGrant(
        id: 'team-allotment',
        org: 'org_a',
        pool: Pools::included(),
        denomination: $calls,
        remaining: $units,
        expiresAt: null,
        grantedAt: 1_000,
        cadence: GrantCadence::Recurring,
    );
}

it('forfeits the outgoing allotment and regrants the incoming plan on a switch', function (): void {
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $this->calls, 40, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('topup', 'org_a', Pools::purchased(), $this->calls, 20, expiresAt: null, grantedAt: 1));

    $lifecycle = $this->lifecycleForfeitingInto($wallet);
    $outcome = $lifecycle->switchPlan(
        $this->from,
        $this->to,
        new PlanSwitchConsequence(carryOver: false, incomingAllotment: incomingAllotment(100, $this->calls)),
        now: 1_000,
    );

    expect($outcome->subscription?->id)->toBe('sub_2')
        ->and($outcome->forfeited->total())->toBe(40)                                             // outgoing allotment zeroed
        ->and($wallet->balance('org_a', Pools::included(), $this->calls, now: 1_000))->toBe(100)   // reset to the incoming plan's
        ->and($wallet->balance('org_a', Pools::purchased(), $this->calls, now: 1_000))->toBe(20);  // purchased carries over untouched
});

it('keeps the outgoing allotment when the switch carries over', function (): void {
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $this->calls, 40, expiresAt: null, grantedAt: 1));

    $lifecycle = $this->lifecycleForfeitingInto($wallet);
    $outcome = $lifecycle->switchPlan(
        $this->from,
        $this->to,
        new PlanSwitchConsequence(carryOver: true, incomingAllotment: incomingAllotment(100, $this->calls)),
        now: 1_000,
    );

    expect($outcome->forfeited->isEmpty())->toBeTrue()
        // 40 kept + 100 incoming both live in the included pool.
        ->and($wallet->balance('org_a', Pools::included(), $this->calls, now: 1_000))->toBe(140);
});

it('never offsets a negative pay-as-you-go pool when forfeiting on a switch', function (): void {
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $this->calls, 40, expiresAt: null, grantedAt: 1));
    // A pay-as-you-go pool driven negative by overage.
    $wallet->grant(new CreditGrant('debt', 'org_a', Pools::purchased(), $this->calls, -30, expiresAt: null, grantedAt: 1));

    $lifecycle = $this->lifecycleForfeitingInto($wallet);
    $lifecycle->switchPlan(
        $this->from,
        $this->to,
        new PlanSwitchConsequence(carryOver: false, incomingAllotment: incomingAllotment(100, $this->calls)),
        now: 1_000,
    );

    // The forfeiture only zeroes the forfeitable pool; the PAYG debt survives.
    expect($wallet->balance('org_a', Pools::purchased(), $this->calls, now: 1_000))->toBe(-30)
        ->and($wallet->balance('org_a', Pools::included(), $this->calls, now: 1_000))->toBe(100);
});

it('defers forfeiture to period end for a cancel-at-period-end, not at request time', function (): void {
    $wallet = $this->wallet();
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $this->calls, 40, expiresAt: null, grantedAt: 1));

    // Requesting cancel-at-period-end is a pure state change: the sub stays active, so a
    // transition onto it does not leave-without-landing and nothing is forfeited yet.
    $canceling = $this->manager->cancelAtPeriodEnd($this->from);
    $atRequest = $this->lifecycleForfeitingInto($wallet)->switchTo($this->from, $canceling, now: 1_000);
    expect($canceling->isActive())->toBeTrue()
        ->and($atRequest->forfeited->isEmpty())->toBeTrue()
        ->and($wallet->balance('org_a', Pools::included(), $this->calls, now: 1_000))->toBe(40);

    // Only when renewal at period end enacts the cancellation does it forfeit.
    $ended = $this->lifecycleForfeitingInto($wallet)->renew($canceling, $this->nextPeriod, now: 2_000);
    expect($ended->subscription?->isActive())->toBeFalse()
        ->and($ended->forfeited->total())->toBe(40)
        ->and($wallet->balance('org_a', Pools::included(), $this->calls, now: 2_000))->toBe(0);
});
