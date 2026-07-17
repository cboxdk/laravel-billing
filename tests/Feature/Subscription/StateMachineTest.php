<?php

declare(strict_types=1);

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\Exceptions\IllegalStateTransition;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;

beforeEach(function (): void {
    $this->manager = new SubscriptionManager;
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $this->nextPeriod = new BillingPeriod(new DateTimeImmutable('2025-10-01'), new DateTimeImmutable('2025-11-01'));
    $this->sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period);
});

it('converts trialing → active', function (): void {
    $trial = $this->manager->create('sub_t', 'org_1', 'pro', 'pro-v1', $this->period, new DateTimeImmutable('2025-09-15'));

    $active = $this->manager->convertTrial($trial, new DateTimeImmutable('2025-09-15'));

    expect($trial->status)->toBe(SubscriptionStatus::Trialing)
        ->and($active->status)->toBe(SubscriptionStatus::Active);
});

it('marks past_due on a payment failure and recovers back to active', function (): void {
    $pastDue = $this->manager->markPastDue($this->sub);
    expect($pastDue->status)->toBe(SubscriptionStatus::PastDue)
        ->and($pastDue->isActive())->toBeTrue(); // still serving during dunning

    $recovered = $this->manager->recover($pastDue);
    expect($recovered->status)->toBe(SubscriptionStatus::Active);
});

it('pauses (no billing while paused) and resumes with the period shifted by the paused span', function (): void {
    $paused = $this->manager->pause($this->sub, new DateTimeImmutable('2025-09-10'));
    expect($paused->status)->toBe(SubscriptionStatus::Paused)
        ->and($paused->isActive())->toBeFalse()
        ->and($paused->pausedAt)->toEqual(new DateTimeImmutable('2025-09-10'));

    // A renewal while paused does nothing — no billing, no period advance.
    $stillPaused = $this->manager->renew($paused, $this->nextPeriod);
    expect($stillPaused->status)->toBe(SubscriptionStatus::Paused)
        ->and($stillPaused->period->start)->toEqual($this->period->start);

    // Resume 10 days later: [2025-09-01, 2025-10-01) shifts forward by 10 days.
    $resumed = $this->manager->resume($paused, new DateTimeImmutable('2025-09-20'));
    expect($resumed->status)->toBe(SubscriptionStatus::Active)
        ->and($resumed->pausedAt)->toBeNull()
        ->and($resumed->period->start)->toEqual(new DateTimeImmutable('2025-09-11'))
        ->and($resumed->period->end)->toEqual(new DateTimeImmutable('2025-10-11'));
});

it('cancels at period end (non_renewing, still serving) then cancels on renewal', function (): void {
    $nonRenewing = $this->manager->cancelAtPeriodEnd($this->sub);
    expect($nonRenewing->status)->toBe(SubscriptionStatus::NonRenewing)
        ->and($nonRenewing->isActive())->toBeTrue()
        ->and($nonRenewing->cancelAtPeriodEnd)->toBeTrue();

    $canceled = $this->manager->renew($nonRenewing, $this->nextPeriod);
    expect($canceled->status)->toBe(SubscriptionStatus::Canceled)
        ->and($canceled->isActive())->toBeFalse();
});

it('resumes a non_renewing subscription back to active before it renews', function (): void {
    $nonRenewing = $this->manager->cancelAtPeriodEnd($this->sub);

    $resumed = $this->manager->resume($nonRenewing);
    expect($resumed->status)->toBe(SubscriptionStatus::Active)
        ->and($resumed->cancelAtPeriodEnd)->toBeFalse();

    // It now renews normally rather than canceling.
    $renewed = $this->manager->renew($resumed, $this->nextPeriod);
    expect($renewed->status)->toBe(SubscriptionStatus::Active);
});

it('cancels immediately to canceled', function (): void {
    $canceled = $this->manager->cancelNow($this->sub);

    expect($canceled->status)->toBe(SubscriptionStatus::Canceled)
        ->and($canceled->isActive())->toBeFalse();
});

it('refuses an illegal transition: a canceled subscription is terminal', function (): void {
    $canceled = $this->manager->cancelNow($this->sub);

    expect(fn () => $this->manager->resume($canceled))
        ->toThrow(IllegalStateTransition::class);
});

it('refuses an illegal transition: a paused subscription cannot go past_due', function (): void {
    $paused = $this->manager->pause($this->sub, new DateTimeImmutable('2025-09-10'));

    expect(fn () => $this->manager->markPastDue($paused))
        ->toThrow(IllegalStateTransition::class);
});

it('carries the from/to states on an illegal transition', function (): void {
    $canceled = $this->manager->cancelNow($this->sub);

    try {
        $this->manager->pause($canceled, new DateTimeImmutable('2025-09-10'));
        $this->fail('expected IllegalStateTransition');
    } catch (IllegalStateTransition $e) {
        expect($e->from)->toBe(SubscriptionStatus::Canceled)
            ->and($e->to)->toBe(SubscriptionStatus::Paused);
    }
});
