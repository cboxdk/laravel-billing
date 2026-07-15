<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\ValueObjects\SellerRegistrations;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->previewer = $this->app->make(PlanChangePreviewer::class);
    $this->period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $this->midPeriod = new DateTimeImmutable('2025-09-16'); // 15 of 30 days remaining
});

function dkContext(): QuoteContext
{
    return new QuoteContext(
        place: test()->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
    );
}

it('computes the day-based billing period', function () {
    expect($this->period->totalDays())->toBe(30)
        ->and($this->period->remainingDays($this->midPeriod))->toBe(15)
        ->and($this->period->remainingDays(new DateTimeImmutable('2025-10-05')))->toBe(0);
});

it('prorates a price change over the remaining period', function () {
    $prorated = (new ProrationCalculator)->prorate(
        Money::ofMinor(5000, 'EUR'),  // current 50.00
        Money::ofMinor(6000, 'EUR'),  // new 60.00
        $this->period,
        $this->midPeriod,
    );

    // (60 - 50) * 15/30 = 5.00
    expect($prorated->minor())->toBe(500);
});

it('previews an upgrade: prorated charge now (taxed), effective immediately', function () {
    $preview = $this->previewer->preview(
        Money::ofMinor(5000, 'EUR'),
        Money::ofMinor(6000, 'EUR'),
        $this->period,
        $this->midPeriod,
        dkContext(),
    );

    expect($preview->isUpgrade)->toBeTrue()
        ->and($preview->proratedNet->minor())->toBe(500)
        ->and($preview->dueNowQuote)->not->toBeNull()
        ->and($preview->dueNowQuote->totals->gross->minor())->toBe(625) // 5.00 + 25% DK VAT
        ->and($preview->newRecurring->minor())->toBe(6000)
        ->and($preview->effectiveAt)->toEqual($this->midPeriod);
});

it('previews a downgrade: scheduled at period end, nothing due now', function () {
    $preview = $this->previewer->preview(
        Money::ofMinor(6000, 'EUR'),  // current 60.00
        Money::ofMinor(5000, 'EUR'),  // new 50.00
        $this->period,
        $this->midPeriod,
        dkContext(),
    );

    expect($preview->isUpgrade)->toBeFalse()
        ->and($preview->dueNowQuote)->toBeNull()
        ->and($preview->proratedNet->minor())->toBe(0)
        ->and($preview->newRecurring->minor())->toBe(5000)
        ->and($preview->effectiveAt)->toEqual($this->period->end);
});
