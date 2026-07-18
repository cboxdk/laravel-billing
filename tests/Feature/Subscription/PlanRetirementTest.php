<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\InMemoryCatalog;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\PlanChange\Exceptions\TransitionNotAllowed;
use Cbox\Billing\Subscription\PlanChange\FamilyTransitionPolicy;
use Cbox\Billing\Subscription\Retirement\Enums\RetirementOutcome;
use Cbox\Billing\Subscription\Retirement\Exceptions\RetirementNotResolved;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\Subscription;

/**
 * Plan retirement / sunset (ADR-0016). A `beta` plan retires at the 2026-06-01 cutoff and
 * falls to `hosted-pro` (same family) when a subscriber makes no choice; existing
 * subscribers resolve at their next renewal on/after the cutoff.
 */
beforeEach(function (): void {
    $this->manager = new SubscriptionManager;
    $this->cutoff = new DateTimeImmutable('2026-06-01');

    // The successor a same-family migration lands on, and its effective price.
    $this->successor = $this->plan('hosted-pro', family: 'hosted');
    $this->successorPrice = new Price('hosted-pro-v1', 'hosted-pro', PricingModel::Flat, Money::ofMinor(5000, 'EUR'), new DateTimeImmutable('2024-01-01'));

    // The retiring plan, defaulting to hosted-pro for subscribers who do not choose.
    $this->beta = $this->retiringPlan('beta', $this->cutoff, defaultSuccessorPlanId: 'hosted-pro', family: 'hosted');

    // A retiring plan with no default configured — deny-by-default territory.
    $this->betaNoDefault = $this->retiringPlan('beta', $this->cutoff, family: 'hosted');

    $this->catalog = new InMemoryCatalog([$this->beta, $this->successor], [$this->successorPrice]);

    // A subscription on `beta` whose current period ends on `$end`.
    $this->onBeta = function (DateTimeImmutable $end): Subscription {
        $start = $end->sub(new DateInterval('P1M'));

        return $this->manager->create('sub_1', 'org_1', 'beta', 'beta-v1', new BillingPeriod($start, $end));
    };
});

it('is NotRetiring for a renewal before the cutoff — no paid time is lost', function (): void {
    // Renewing 2026-05-15, before the 2026-06-01 cutoff.
    $sub = ($this->onBeta)(new DateTimeImmutable('2026-05-15'));

    $resolution = $this->retirementResolver()->resolve($sub, $this->catalog, new DateTimeImmutable('2026-05-15'));

    expect($resolution->outcome)->toBe(RetirementOutcome::NotRetiring);

    // The renewal proceeds normally: still on beta, advanced a period, Active.
    $renewed = $this->retirementRenewalPolicy()->renew(
        $sub,
        $this->catalog,
        new BillingPeriod(new DateTimeImmutable('2026-05-15'), new DateTimeImmutable('2026-06-15')),
        new DateTimeImmutable('2026-05-15'),
    );

    expect($renewed->productId)->toBe('beta')
        ->and($renewed->status)->toBe(SubscriptionStatus::Active)
        ->and($renewed->periodIndex)->toBe(1);
});

it('resolves to the default successor when a retired subscriber made no choice', function (): void {
    // Renewing 2026-06-10, on/after the cutoff, no choice, default = hosted-pro.
    $sub = ($this->onBeta)(new DateTimeImmutable('2026-06-10'));
    $now = new DateTimeImmutable('2026-06-10');

    $resolution = $this->retirementResolver()->resolve($sub, $this->catalog, $now);

    expect($resolution->outcome)->toBe(RetirementOutcome::ResolvedToDefault)
        ->and($resolution->successorPlanId)->toBe('hosted-pro');

    // Enacting migrates onto hosted-pro at its effective price.
    $renewed = $this->retirementRenewalPolicy()->renew(
        $sub,
        $this->catalog,
        new BillingPeriod($now, new DateTimeImmutable('2026-07-10')),
        $now,
    );

    expect($renewed->productId)->toBe('hosted-pro')
        ->and($renewed->priceId)->toBe('hosted-pro-v1')
        ->and($renewed->status)->toBe(SubscriptionStatus::Active)
        ->and($renewed->periodIndex)->toBe(1);
});

it('resolves to a scheduled successor and migrates onto the chosen plan and price', function (): void {
    $sub = ($this->onBeta)(new DateTimeImmutable('2026-06-10'));
    $now = new DateTimeImmutable('2026-06-10');

    // The subscriber elected hosted-pro as their successor ahead of the renewal.
    $sub = $this->manager->schedulePlanChange($sub, 'hosted-pro', 'hosted-pro-v1', $now);

    $resolution = $this->retirementResolver()->resolve($sub, $this->catalog, $now);

    expect($resolution->outcome)->toBe(RetirementOutcome::ResolvedToSuccessor)
        ->and($resolution->successorPlanId)->toBe('hosted-pro');

    $renewed = $this->retirementRenewalPolicy()->renew(
        $sub,
        $this->catalog,
        new BillingPeriod($now, new DateTimeImmutable('2026-07-10')),
        $now,
    );

    expect($renewed->productId)->toBe('hosted-pro')
        ->and($renewed->priceId)->toBe('hosted-pro-v1')
        ->and($renewed->pendingChange)->toBeNull()
        ->and($renewed->status)->toBe(SubscriptionStatus::Active);
});

it('resolves to cancel — a first-class choice — and cancels at the renewal', function (): void {
    $sub = ($this->onBeta)(new DateTimeImmutable('2026-06-10'));
    $now = new DateTimeImmutable('2026-06-10');

    // The subscriber chose to cancel rather than migrate; they keep serving until renewal.
    $sub = $this->manager->cancelAtPeriodEnd($sub);

    $resolution = $this->retirementResolver()->resolve($sub, $this->catalog, $now);

    expect($resolution->outcome)->toBe(RetirementOutcome::ResolvedToCancel);

    $renewed = $this->retirementRenewalPolicy()->renew(
        $sub,
        $this->catalog,
        new BillingPeriod($now, new DateTimeImmutable('2026-07-10')),
        $now,
    );

    expect($renewed->status)->toBe(SubscriptionStatus::Canceled)
        ->and($renewed->productId)->toBe('beta');
});

it('refuses the renewal when retired with no default and no choice (deny-by-default)', function (): void {
    $catalog = new InMemoryCatalog([$this->betaNoDefault, $this->successor], [$this->successorPrice]);
    $sub = ($this->onBeta)(new DateTimeImmutable('2026-06-10'));
    $now = new DateTimeImmutable('2026-06-10');

    $resolution = $this->retirementResolver()->resolve($sub, $catalog, $now);

    expect($resolution->outcome)->toBe(RetirementOutcome::UnresolvedRetirement);

    // The renewal is refused — never a silent charge/continuation on the retired plan.
    expect(fn () => $this->retirementRenewalPolicy()->renew(
        $sub,
        $catalog,
        new BillingPeriod($now, new DateTimeImmutable('2026-07-10')),
        $now,
    ))->toThrow(RetirementNotResolved::class);
});

it('rejects an illegal successor migration through the transition policy', function (): void {
    // The default successor is in a different family with no declared edge — illegal.
    $onPrem = $this->plan('on-prem', family: 'on-prem');
    $betaToOnPrem = $this->retiringPlan('beta', $this->cutoff, defaultSuccessorPlanId: 'on-prem', family: 'hosted');
    $onPremPrice = new Price('on-prem-v1', 'on-prem', PricingModel::Flat, Money::ofMinor(9000, 'EUR'), new DateTimeImmutable('2024-01-01'));
    $catalog = new InMemoryCatalog([$betaToOnPrem, $onPrem], [$onPremPrice]);

    $sub = ($this->onBeta)(new DateTimeImmutable('2026-06-10'));
    $now = new DateTimeImmutable('2026-06-10');

    // The resolver still resolves to the configured default…
    expect($this->retirementResolver()->resolve($sub, $catalog, $now)->outcome)
        ->toBe(RetirementOutcome::ResolvedToDefault);

    // …but enacting it validates through the policy and refuses the cross-family jump.
    expect(fn () => $this->retirementRenewalPolicy(new FamilyTransitionPolicy)->renew(
        $sub,
        $catalog,
        new BillingPeriod($now, new DateTimeImmutable('2026-07-10')),
        $now,
    ))->toThrow(TransitionNotAllowed::class);
});

it('reports RetiringChooseBy with the deadline while the subscriber still has paid time', function (): void {
    // Retired at the cutoff, but this subscriber is mid-period (renews 2026-06-30).
    $sub = ($this->onBeta)(new DateTimeImmutable('2026-06-30'));
    $now = new DateTimeImmutable('2026-06-05'); // past the cutoff, before the renewal

    $resolution = $this->retirementResolver()->resolve($sub, $this->catalog, $now);

    expect($resolution->outcome)->toBe(RetirementOutcome::RetiringChooseBy)
        ->and($resolution->renewalDueDate)->toEqual(new DateTimeImmutable('2026-06-30'))
        ->and($resolution->defaultSuccessorPlanId)->toBe('hosted-pro');
});

it('never allows a being-retired plan as a transition target', function (): void {
    $policy = new FamilyTransitionPolicy;

    $decision = $policy->canTransition($this->successor, $this->beta);

    expect($decision->isAllowed())->toBeFalse()
        ->and($decision->reason)->toContain('being retired');
});
