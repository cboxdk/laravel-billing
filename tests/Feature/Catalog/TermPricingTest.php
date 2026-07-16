<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Enums\ProductShape;
use Cbox\Billing\Catalog\Enums\TermUnit;
use Cbox\Billing\Catalog\InMemoryCatalog;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Money\Money;

function year(int $count): Term
{
    return new Term($count, TermUnit::Year);
}

function domainPrice(string $id, Term $term, PriceKind $kind, int $minor, string $from, ?string $until = null): Price
{
    return new Price(
        $id,
        'domain-com',
        PricingModel::Flat,
        Money::ofMinor($minor, 'EUR'),
        new DateTimeImmutable($from),
        $until === null ? null : new DateTimeImmutable($until),
        $term,
        $kind,
    );
}

function domainCatalog(): InMemoryCatalog
{
    return new InMemoryCatalog(
        products: [new Product('domain-com', '.com domain', shape: ProductShape::FixedTerm)],
        prices: [
            // Register: 1yr 10.00, 2yr 19.00, 5yr 45.00
            domainPrice('reg-1y', year(1), PriceKind::Register, 1000, '2024-01-01'),
            domainPrice('reg-2y', year(2), PriceKind::Register, 1900, '2024-01-01'),
            domainPrice('reg-5y', year(5), PriceKind::Register, 4500, '2024-01-01'),
            // Renewal: 1yr 12.00, 2yr 23.00, 5yr 55.00
            domainPrice('ren-1y', year(1), PriceKind::Renewal, 1200, '2024-01-01'),
            domainPrice('ren-2y', year(2), PriceKind::Renewal, 2300, '2024-01-01'),
            domainPrice('ren-5y', year(5), PriceKind::Renewal, 5500, '2024-01-01'),
            // Redemption (recovery premium): 1yr 90.00
            domainPrice('red-1y', year(1), PriceKind::Redemption, 9000, '2024-01-01'),
            // Transfer: 1yr 11.00
            domainPrice('tra-1y', year(1), PriceKind::Transfer, 1100, '2024-01-01'),
        ],
    );
}

it('picks the right price point per (term x kind)', function () {
    $catalog = domainCatalog();
    $at = new DateTimeImmutable('2025-01-01');

    expect($catalog->termPriceFor('domain-com', year(1), PriceKind::Register, $at)->unitAmount->minor())->toBe(1000)
        ->and($catalog->termPriceFor('domain-com', year(2), PriceKind::Register, $at)->unitAmount->minor())->toBe(1900)
        ->and($catalog->termPriceFor('domain-com', year(5), PriceKind::Register, $at)->unitAmount->minor())->toBe(4500)
        ->and($catalog->termPriceFor('domain-com', year(1), PriceKind::Renewal, $at)->unitAmount->minor())->toBe(1200)
        ->and($catalog->termPriceFor('domain-com', year(5), PriceKind::Renewal, $at)->unitAmount->minor())->toBe(5500)
        ->and($catalog->termPriceFor('domain-com', year(1), PriceKind::Redemption, $at)->unitAmount->minor())->toBe(9000)
        ->and($catalog->termPriceFor('domain-com', year(1), PriceKind::Transfer, $at)->unitAmount->minor())->toBe(1100);
});

it('returns null for a (term x kind) point the catalog does not offer', function () {
    $catalog = domainCatalog();
    $at = new DateTimeImmutable('2025-01-01');

    // No 2yr redemption, no 5yr transfer point published.
    expect($catalog->termPriceFor('domain-com', year(2), PriceKind::Redemption, $at))->toBeNull()
        ->and($catalog->termPriceFor('domain-com', year(5), PriceKind::Transfer, $at))->toBeNull()
        ->and($catalog->termPriceFor('domain-com', year(3), PriceKind::Register, $at))->toBeNull();
});

it('grandfathers a term price by effective date — an old instance keeps the earlier price', function () {
    // A 2yr Register price rises from 19.00 to 25.00 effective 2025-07-01.
    $catalog = new InMemoryCatalog(
        products: [new Product('domain-com', '.com domain', shape: ProductShape::FixedTerm)],
        prices: [
            domainPrice('reg-2y-v1', year(2), PriceKind::Register, 1900, '2024-01-01', '2025-07-01'),
            domainPrice('reg-2y-v2', year(2), PriceKind::Register, 2500, '2025-07-01'),
        ],
    );

    // An instance registered 2025-01-01 pins the v1 price; a new sale after the rise gets v2.
    $pinned = $catalog->termPriceFor('domain-com', year(2), PriceKind::Register, new DateTimeImmutable('2025-01-01'));
    $current = $catalog->termPriceFor('domain-com', year(2), PriceKind::Register, new DateTimeImmutable('2025-09-01'));

    expect($pinned->unitAmount->minor())->toBe(1900)
        ->and($current->unitAmount->minor())->toBe(2500);
});

it('keeps priceFor (non-term) and termPriceFor from colliding on a mixed catalog', function () {
    $catalog = new InMemoryCatalog(
        products: [new Product('domain-com', '.com domain', shape: ProductShape::FixedTerm)],
        prices: [
            // A plain versioned price with no term dimension.
            new Price('flat', 'domain-com', PricingModel::Flat, Money::ofMinor(500, 'EUR'), new DateTimeImmutable('2024-01-01')),
            domainPrice('reg-1y', year(1), PriceKind::Register, 1000, '2024-01-01'),
        ],
    );
    $at = new DateTimeImmutable('2025-01-01');

    // priceFor ignores term-dimensioned prices; termPriceFor ignores the plain one.
    expect($catalog->priceFor('domain-com', $at)->unitAmount->minor())->toBe(500)
        ->and($catalog->termPriceFor('domain-com', year(1), PriceKind::Register, $at)->unitAmount->minor())->toBe(1000);
});

it('builds a term duration and its ISO-8601 form', function () {
    expect(year(2)->toIso8601())->toBe('P2Y')
        ->and((new Term(6, TermUnit::Month))->toIso8601())->toBe('P6M')
        ->and((new Term(30, TermUnit::Day))->toIso8601())->toBe('P30D')
        ->and(year(1)->addTo(new DateTimeImmutable('2026-01-15')))->toEqual(new DateTimeImmutable('2027-01-15'))
        ->and(year(2)->equals(year(2)))->toBeTrue()
        ->and(year(2)->equals(year(1)))->toBeFalse();

    expect(fn () => new Term(0, TermUnit::Year))->toThrow(InvalidArgumentException::class);
});
