<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\TrueUpCalculator;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\MinimumCommitment;

it('true-ups the shortfall when the period is below the committed minimum', function (): void {
    $minimum = Money::ofMinor(10000, 'USD');
    $actual = Money::ofMinor(7000, 'USD');

    expect(TrueUpCalculator::shortfall($minimum, $actual)->minor())->toBe(3000);
});

it('true-ups to zero when the period met or exceeded the minimum', function (): void {
    $minimum = Money::ofMinor(10000, 'USD');
    $actual = Money::ofMinor(12000, 'USD');

    $shortfall = TrueUpCalculator::shortfall($minimum, $actual);
    expect($shortfall->minor())->toBe(0)
        ->and($shortfall->isZero())->toBeTrue()
        ->and($shortfall->currency())->toBe('USD');
});

it('true-ups to zero when the period exactly hits the minimum', function (): void {
    $minimum = Money::ofMinor(10000, 'USD');

    expect(TrueUpCalculator::shortfall($minimum, $minimum)->minor())->toBe(0);
});

it('exposes the true-up through a commitment carried on the subscription', function (): void {
    $manager = new SubscriptionManager;
    $period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));

    $sub = $manager->withMinimumCommitment(
        $manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $period),
        new MinimumCommitment(Money::ofMinor(10000, 'USD')),
    );

    expect($sub->trueUp(Money::ofMinor(7000, 'USD'))->minor())->toBe(3000)
        ->and($sub->trueUp(Money::ofMinor(12000, 'USD'))->minor())->toBe(0);
});

it('a subscription with no commitment true-ups to zero', function (): void {
    $manager = new SubscriptionManager;
    $period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $sub = $manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $period);

    expect($sub->trueUp(Money::ofMinor(7000, 'USD'))->minor())->toBe(0);
});
