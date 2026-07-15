<?php

declare(strict_types=1);

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;

beforeEach(function () {
    $this->manager = new SubscriptionManager;
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $this->nextPeriod = new BillingPeriod(new DateTimeImmutable('2025-10-01'), new DateTimeImmutable('2025-11-01'));
});

it('creates an active subscription', function () {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period);

    expect($sub->isActive())->toBeTrue()
        ->and($sub->priceId)->toBe('pro-v1')
        ->and($sub->cancelAtPeriodEnd)->toBeFalse();
});

it('cancels at period end, staying active until it renews into cancellation', function () {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period);

    $sub = $this->manager->cancelAtPeriodEnd($sub);
    expect($sub->isActive())->toBeTrue()->and($sub->cancelAtPeriodEnd)->toBeTrue();

    $sub = $this->manager->renew($sub, $this->nextPeriod);
    expect($sub->status)->toBe(SubscriptionStatus::Canceled);
});

it('resumes a scheduled cancellation before period end', function () {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period);
    $sub = $this->manager->resume($this->manager->cancelAtPeriodEnd($sub));

    $sub = $this->manager->renew($sub, $this->nextPeriod);
    expect($sub->isActive())->toBeTrue();
});

it('applies a scheduled price change on renewal', function () {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period);
    $sub = $this->manager->scheduleChange($sub, 'pro-v2', new DateTimeImmutable('2025-10-01'));

    expect($sub->pendingChange)->not->toBeNull();

    $sub = $this->manager->renew($sub, $this->nextPeriod);
    expect($sub->priceId)->toBe('pro-v2')
        ->and($sub->pendingChange)->toBeNull()
        ->and($sub->period->start)->toEqual($this->nextPeriod->start);
});

it('lets a scheduled change be replaced (mutable) before it applies', function () {
    $sub = $this->manager->create('sub_1', 'org_1', 'pro', 'pro-v1', $this->period);
    $sub = $this->manager->scheduleChange($sub, 'pro-v2', new DateTimeImmutable('2025-10-01'));
    $sub = $this->manager->scheduleChange($sub, 'pro-v3', new DateTimeImmutable('2025-10-01')); // replaces

    $sub = $this->manager->renew($sub, $this->nextPeriod);
    expect($sub->priceId)->toBe('pro-v3');
});
