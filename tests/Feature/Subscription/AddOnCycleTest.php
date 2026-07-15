<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\Enums\CreditGrantMode;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\AddOn;
use Cbox\Billing\Subscription\ValueObjects\BillingCycle;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;

beforeEach(function (): void {
    $this->calculator = new ProrationCalculator;
    $this->manager = new SubscriptionManager;

    // Base subscription cycle: a 30-day September period, mid-cycle leaves 15 days.
    $this->basePeriod = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $this->at = new DateTimeImmutable('2025-09-16');
    $this->price = Money::ofMinor(3000, 'EUR');
});

it('an aligned add-on prorates a mid-cycle add to the base period', function (): void {
    $addOn = new AddOn('seats', 'price_seats', $this->price); // aligned by default

    // Aligned → the base period is the one it bills over.
    $period = $addOn->periodFor($this->basePeriod, $this->at);

    expect($addOn->alignment)->toBe(AddOnAlignment::Aligned)
        ->and($period)->toBe($this->basePeriod)
        // 3000 * 15/30 = 1500 over the base period's remaining days.
        ->and($addOn->proratedCharge($this->calculator, $this->basePeriod, $this->at)->minor())->toBe(1500);
});

it('an independent add-on prorates to its own cycle, not the base period', function (): void {
    $ownCycle = BillingCycle::calendarFirst(BillingInterval::Yearly); // [2025-01-01, 2026-01-01]
    $addOn = new AddOn('support', 'price_support', $this->price, AddOnAlignment::Independent, $ownCycle);

    $period = $addOn->periodFor($this->basePeriod, $this->at);

    // Its own yearly period, not the base month.
    expect($addOn->isIndependent())->toBeTrue()
        ->and($period->start->format('Y-m-d'))->toBe('2025-01-01')
        ->and($period->end->format('Y-m-d'))->toBe('2026-01-01')
        ->and($period)->not->toBe($this->basePeriod)
        // 3000 * 107/365 (days remaining in the year) = 879, not the aligned 1500.
        ->and($addOn->proratedCharge($this->calculator, $this->basePeriod, $this->at)->minor())->toBe(879);
});

it('an aligned add-on grants credits on the base period; an independent one on its own', function (): void {
    $aligned = new AddOn('seats', 'price_seats', $this->price, creditAllotment: 30_000);

    $ownCycle = BillingCycle::calendarFirst(BillingInterval::Yearly);
    $independent = new AddOn('support', 'price_support', $this->price, AddOnAlignment::Independent, $ownCycle, 30_000);

    // Full reset grants the whole allotment for both modes.
    expect($aligned->grantedAllotment($this->basePeriod, $this->at, CreditGrantMode::FullReset))->toBe(30_000)
        ->and($independent->grantedAllotment($this->basePeriod, $this->at, CreditGrantMode::FullReset))->toBe(30_000);

    // Prorated: aligned follows the base period (15/30 → 15_000); independent follows its own year.
    expect($aligned->grantedAllotment($this->basePeriod, $this->at, CreditGrantMode::Prorated))->toBe(15_000)
        ->and($independent->grantedAllotment($this->basePeriod, $this->at, CreditGrantMode::Prorated))->toBe(8_774)
        ->and($independent->grantedAllotment($this->basePeriod, $this->at, CreditGrantMode::Prorated))
        ->not->toBe($aligned->grantedAllotment($this->basePeriod, $this->at, CreditGrantMode::Prorated));
});

it('rejects an independent add-on with no cycle of its own', function (): void {
    expect(fn () => new AddOn('bad', 'price_bad', $this->price, AddOnAlignment::Independent))
        ->toThrow(InvalidArgumentException::class);
});

it('attaches and detaches add-ons on the subscription, matched by id', function (): void {
    $cycle = BillingCycle::anchoredOnSignup($this->at, BillingInterval::Monthly);
    $subscription = $this->manager->createOnCycle('sub_1', 'org_a', 'prod', 'price', $cycle, $this->at);

    // createOnCycle carries the cycle and opens the anchored period.
    expect($subscription->cycle)->toBe($cycle)
        ->and($subscription->period->start->format('Y-m-d'))->toBe('2025-09-16')
        ->and($subscription->addOns)->toBe([]);

    $seats = new AddOn('seats', 'price_seats', $this->price);
    $support = new AddOn('support', 'price_support', $this->price, AddOnAlignment::Independent, BillingCycle::calendarFirst(BillingInterval::Yearly));

    $withAddOns = $this->manager->addAddOn($this->manager->addAddOn($subscription, $seats), $support);

    expect($withAddOns->hasAddOn('seats'))->toBeTrue()
        ->and($withAddOns->hasAddOn('support'))->toBeTrue()
        ->and($withAddOns->addOns)->toHaveCount(2)
        // Re-adding by the same id replaces rather than duplicates.
        ->and($this->manager->addAddOn($withAddOns, $seats)->addOns)->toHaveCount(2);

    $removed = $this->manager->removeAddOn($withAddOns, 'seats');

    expect($removed->hasAddOn('seats'))->toBeFalse()
        ->and($removed->addOns)->toHaveCount(1)
        // The base subscription and its cycle survive add-on churn.
        ->and($removed->cycle)->toBe($cycle);
});

it('renews a cycle-anchored subscription onto its next period', function (): void {
    $cycle = new BillingCycle(31, 1, BillingInterval::Monthly, new DateTimeZone('UTC'));
    $start = new DateTimeImmutable('2024-01-31 12:00:00', new DateTimeZone('UTC'));

    $subscription = $this->manager->createOnCycle('sub_1', 'org_a', 'prod', 'price', $cycle, $start);
    $renewed = $this->manager->renewOnCycle($subscription, $start);

    // [Jan 31, Feb 29) → [Feb 29, Mar 31): the 31 anchor is preserved, Feb clamps to 29.
    expect($subscription->period->end->format('Y-m-d'))->toBe('2024-02-29')
        ->and($renewed->period->start->format('Y-m-d'))->toBe('2024-02-29')
        ->and($renewed->period->end->format('Y-m-d'))->toBe('2024-03-31');
});
