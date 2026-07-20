<?php

declare(strict_types=1);

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\Exceptions\TrialNotEnded;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;

beforeEach(function (): void {
    $this->manager = new SubscriptionManager;
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
});

it('opens a trial subscription that is trialing and charges nothing during the trial', function (): void {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period, new DateTimeImmutable('2025-09-15'));

    expect($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->isTrialing())->toBeTrue()
        ->and($sub->isActive())->toBeTrue() // a trial still serves the plan
        ->and($sub->trialEndsAt)->toEqual(new DateTimeImmutable('2025-09-15'))
        ->and($sub->effectiveRecurringAmount())->toBeNull(); // no ramp → catalog price, nothing forced now
});

it('opens a trial via the explicit startTrial factory', function (): void {
    $sub = $this->manager->startTrial('sub_1', 'org_1', 'pro', 'pro-v1', $this->period, new DateTimeImmutable('2025-09-15'));

    expect($sub->status)->toBe(SubscriptionStatus::Trialing);
});

it('a plain create (no trial) opens active', function (): void {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period);

    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->isTrialing())->toBeFalse();
});

it('converts a trial to active (first charge) and clears the trial marker', function (): void {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period, new DateTimeImmutable('2025-09-15'));

    $converted = $this->manager->convertTrial($sub, new DateTimeImmutable('2025-09-15'));

    expect($converted->status)->toBe(SubscriptionStatus::Active)
        ->and($converted->isTrialing())->toBeFalse()
        ->and($converted->trialEndsAt)->toBeNull();
});

it('refuses to convert a trial before trialEndsAt (no early charge)', function (): void {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period, new DateTimeImmutable('2025-09-15'));

    // One millisecond before the trial ends — the customer must not be charged early.
    expect(fn () => $this->manager->convertTrial($sub, new DateTimeImmutable('2025-09-14 23:59:59')))
        ->toThrow(TrialNotEnded::class);
});

it('converts a trial exactly at trialEndsAt and after it', function (): void {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period, new DateTimeImmutable('2025-09-15'));

    $atBoundary = $this->manager->convertTrial($sub, new DateTimeImmutable('2025-09-15'));
    $afterBoundary = $this->manager->convertTrial($sub, new DateTimeImmutable('2025-09-20'));

    expect($atBoundary->status)->toBe(SubscriptionStatus::Active)
        ->and($afterBoundary->status)->toBe(SubscriptionStatus::Active);
});

it('force-converts a trial early through the explicit path', function (): void {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period, new DateTimeImmutable('2025-09-15'));

    $converted = $this->manager->forceConvertTrial($sub);

    expect($converted->status)->toBe(SubscriptionStatus::Active)
        ->and($converted->isTrialing())->toBeFalse()
        ->and($converted->trialEndsAt)->toBeNull();
});
