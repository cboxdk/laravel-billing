<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\SellerRegistrations;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->builder = $this->app->make(QuoteBuilder::class);
});

it('builds a tax-resolved quote showing the full consequence', function () {
    $quote = $this->builder->build(
        [new LineInput('Pro plan', 2, Money::ofMinor(5000, 'EUR'))], // 2 x 50.00
        new QuoteContext(
            place: $this->geo->find(new CountryCode('DK')),
            customer: CustomerType::Consumer,
            seller: new SellerRegistrations(new CountryCode('DK')),
        ),
    );

    expect($quote->isTaxResolved())->toBeTrue()
        ->and($quote->totals->net->minor())->toBe(10000)
        ->and($quote->totals->tax->minor())->toBe(2500)   // DK 25%
        ->and($quote->totals->gross->minor())->toBe(12500)
        ->and($quote->totals->dueNow->minor())->toBe(12500)
        ->and($quote->lines[0]->treatment)->toBe(TaxTreatment::Standard)
        ->and($quote->lines[0]->taxRatePercentage)->toBe('25');
});

it('applies wallet credit to reduce the amount due now', function () {
    $quote = $this->builder->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, 'EUR'))],
        new QuoteContext(
            place: $this->geo->find(new CountryCode('DK')),
            customer: CustomerType::Consumer,
            seller: new SellerRegistrations(new CountryCode('DK')),
            creditAvailable: Money::ofMinor(3000, 'EUR'),
        ),
    );

    // gross 125.00, credit 30.00, due now 95.00
    expect($quote->totals->gross->minor())->toBe(12500)
        ->and($quote->totals->credit->minor())->toBe(3000)
        ->and($quote->totals->dueNow->minor())->toBe(9500);
});

it('returns a tax-pending quote when the jurisdiction is not resolved (US without a state)', function () {
    $quote = $this->builder->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, 'USD'))],
        new QuoteContext(
            place: $this->geo->find(new CountryCode('US')),
            customer: CustomerType::Consumer,
            seller: new SellerRegistrations(new CountryCode('US')),
        ),
    );

    expect($quote->isTaxResolved())->toBeFalse()
        ->and($quote->taxResolution->reason)->not->toBeNull()
        ->and($quote->totals->tax->minor())->toBe(0)
        ->and($quote->totals->dueNow->minor())->toBe(10000)
        ->and($quote->lines[0]->treatment)->toBeNull();
});

it('reverse-charges an intra-EU B2B quote so nothing tax is due', function () {
    $quote = $this->builder->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, 'EUR'))],
        new QuoteContext(
            place: $this->geo->find(new CountryCode('FR')),
            customer: CustomerType::Business,
            seller: new SellerRegistrations(new CountryCode('DE')),
            customerTaxIdValidated: true,
        ),
    );

    expect($quote->totals->tax->minor())->toBe(0)
        ->and($quote->lines[0]->treatment)->toBe(TaxTreatment::ReverseCharge);
});
