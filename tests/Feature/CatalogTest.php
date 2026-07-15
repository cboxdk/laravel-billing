<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\Enums\PlanStatus;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\InMemoryCatalog;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\ValueObjects\SellerRegistrations;

function catalog(): InMemoryCatalog
{
    return new InMemoryCatalog(
        products: [new Product('pro', 'Pro plan')],
        prices: [
            // v1: 50.00, effective 2024 → 2025-06-30
            new Price('pro-v1', 'pro', PricingModel::Flat, Money::ofMinor(5000, 'EUR'),
                new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2025-07-01')),
            // v2: 60.00, effective 2025-07-01 onward (a price increase)
            new Price('pro-v2', 'pro', PricingModel::Flat, Money::ofMinor(6000, 'EUR'),
                new DateTimeImmutable('2025-07-01')),
        ],
    );
}

it('resolves the price version effective at a date', function () {
    $catalog = catalog();

    expect($catalog->priceFor('pro', new DateTimeImmutable('2025-01-01'))->id)->toBe('pro-v1')
        ->and($catalog->priceFor('pro', new DateTimeImmutable('2025-09-01'))->id)->toBe('pro-v2')
        ->and($catalog->priceFor('unknown', new DateTimeImmutable('2025-09-01')))->toBeNull();
});

it('grandfathers a subscriber pinned to their start date', function () {
    $catalog = catalog();

    // Subscriber started 2025-01-01: keeps v1 (50.00) even after v2 takes effect.
    $pinned = $catalog->priceFor('pro', new DateTimeImmutable('2025-01-01'));
    // A new sale today gets v2 (60.00).
    $current = $catalog->priceFor('pro', new DateTimeImmutable('2025-09-01'));

    expect($pinned->unitAmount->minor())->toBe(5000)
        ->and($current->unitAmount->minor())->toBe(6000);
});

it('defaults an unfamilied plan to its own singleton family, and defaults to offered', function () {
    $singleton = new Product('solo', 'Solo');
    $grouped = new Product('hosted-pro', 'Hosted Pro', family: 'hosted');
    $legacy = new Product('old', 'Old', family: 'hosted', status: PlanStatus::Legacy);

    expect($singleton->family())->toBe('solo')                 // deny-by-default: its own id
        ->and($singleton->isOffered())->toBeTrue()
        ->and($singleton->sameFamilyAs($grouped))->toBeFalse()
        ->and($grouped->family())->toBe('hosted')
        ->and($grouped->isLegacy())->toBeFalse()
        ->and($legacy->isLegacy())->toBeTrue()
        ->and($legacy->sameFamilyAs($grouped))->toBeTrue();  // status does not change the family
});

it('enumerates every plan in the catalog', function () {
    $catalog = new InMemoryCatalog([
        new Product('hosted-pro', 'Hosted Pro', family: 'hosted'),
        new Product('on-prem', 'On-Prem', family: 'on-prem'),
    ]);

    $ids = array_map(static fn (Product $p): string => $p->id, $catalog->products());

    expect($ids)->toEqualCanonicalizing(['hosted-pro', 'on-prem']);
});

it('computes flat vs per-unit amounts', function () {
    $flat = new Price('f', 'p', PricingModel::Flat, Money::ofMinor(5000, 'EUR'), new DateTimeImmutable('2024-01-01'));
    $perUnit = new Price('u', 'p', PricingModel::PerUnit, Money::ofMinor(1000, 'EUR'), new DateTimeImmutable('2024-01-01'));

    expect($flat->amountFor(5)->minor())->toBe(5000)      // flat ignores quantity
        ->and($perUnit->amountFor(5)->minor())->toBe(5000); // 10.00 x 5
});

it('feeds a pinned catalog price into a taxed quote', function () {
    $catalog = catalog();
    $geo = $this->app->make(JurisdictionRepository::class);
    $builder = $this->app->make(QuoteBuilder::class);

    $price = $catalog->priceFor('pro', new DateTimeImmutable('2025-01-01')); // v1, 50.00 flat
    $product = $catalog->product('pro');

    $quote = $builder->build(
        [new LineInput($product->name, $price->billableQuantity(1), $price->unitAmount)],
        new QuoteContext(
            place: $geo->find(new CountryCode('DK')),
            customer: CustomerType::Consumer,
            seller: new SellerRegistrations(new CountryCode('DK')),
        ),
    );

    // 50.00 net + 25% DK VAT = 62.50 gross
    expect($quote->totals->net->minor())->toBe(5000)
        ->and($quote->totals->gross->minor())->toBe(6250);
});
