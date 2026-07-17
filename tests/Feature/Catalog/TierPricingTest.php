<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Exceptions\MalformedTierSet;
use Cbox\Billing\Catalog\InMemoryCatalog;
use Cbox\Billing\Catalog\Pricing\TierCalculator;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\PriceTier;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Money\Money;

function eurMinor(int $minor): Money
{
    return Money::ofMinor($minor, 'EUR');
}

function tier(?int $upTo, int $unit, ?int $flat = null): PriceTier
{
    return new PriceTier($upTo, eurMinor($unit), $flat === null ? null : eurMinor($flat));
}

/**
 * The tier set shared by the graduated/volume vectors:
 *   0–10   @ 1.00 per unit
 *   11–100 @ 0.80 per unit
 *   101+   @ 0.50 per unit
 *
 * @return list<PriceTier>
 */
function standardTiers(): array
{
    return [tier(10, 100), tier(100, 80), tier(null, 50)];
}

it('prices graduated: each slice at its own tier rate, summed across tiers', function () {
    // qty 150 → 10×100 + 90×80 + 50×50 = 1000 + 7200 + 2500 = 10700
    $amount = (new TierCalculator)->price(PricingModel::Graduated, standardTiers(), 150);

    expect($amount->minor())->toBe(10_700)
        ->and($amount->currency())->toBe('EUR');
});

it('prices graduated within a single tier and exactly on a boundary', function () {
    $calc = new TierCalculator;

    // qty 5 → 5×100 = 500 (only the first tier is reached)
    expect($calc->price(PricingModel::Graduated, standardTiers(), 5)->minor())->toBe(500)
        // qty 10 (boundary) → 10×100 = 1000
        ->and($calc->price(PricingModel::Graduated, standardTiers(), 10)->minor())->toBe(1_000)
        // qty 11 → 10×100 + 1×80 = 1080
        ->and($calc->price(PricingModel::Graduated, standardTiers(), 11)->minor())->toBe(1_080)
        // qty 0 → no usage, no charge
        ->and($calc->price(PricingModel::Graduated, standardTiers(), 0)->minor())->toBe(0);
});

it('prices graduated with per-tier flat entry fees', function () {
    // 0–10 @ 1.00/unit + 5.00 entry, 11+ @ 0.80/unit + 2.00 entry
    $tiers = [tier(10, 100, 500), tier(null, 80, 200)];
    $calc = new TierCalculator;

    // qty 5 → 5×100 + 500 = 1000 (only tier 1 reached, its flat applies)
    expect($calc->price(PricingModel::Graduated, $tiers, 5)->minor())->toBe(1_000)
        // qty 20 → (10×100 + 500) + (10×80 + 200) = 1500 + 1000 = 2500 (both flats apply)
        ->and($calc->price(PricingModel::Graduated, $tiers, 20)->minor())->toBe(2_500);
});

it('is remainder-safe: graduated across tiers loses no minor unit', function () {
    // Odd per-unit rates that would drift under float/percentage math, but are exact
    // in integer minor units: 0–3 @ 3.33, 4+ @ 1.11. qty 5 → 3×333 + 2×111 = 1221.
    $tiers = [tier(3, 333), tier(null, 111)];
    $amount = (new TierCalculator)->price(PricingModel::Graduated, $tiers, 5);

    // Independent recomputation: no cents dropped or duplicated.
    expect($amount->minor())->toBe(3 * 333 + 2 * 111)
        ->and($amount->minor())->toBe(1_221);
});

it('prices volume: ALL units at the single tier the total lands in', function () {
    $calc = new TierCalculator;

    // qty 150 → lands in 101+ tier → 150 × 50 = 7500
    expect($calc->price(PricingModel::Volume, standardTiers(), 150)->minor())->toBe(7_500)
        // qty 50 → lands in 11–100 tier → 50 × 80 = 4000
        ->and($calc->price(PricingModel::Volume, standardTiers(), 50)->minor())->toBe(4_000)
        // qty 8 → lands in 0–10 tier → 8 × 100 = 800
        ->and($calc->price(PricingModel::Volume, standardTiers(), 8)->minor())->toBe(800);
});

it('prices volume with a flat tier fee added to the landed tier', function () {
    // 0–100 @ 0.90/unit + 10.00 platform fee, 101+ @ 0.70/unit + 50.00 fee
    $tiers = [tier(100, 90, 1_000), tier(null, 70, 5_000)];

    // qty 200 → 200×70 + 5000 = 19_000
    expect((new TierCalculator)->price(PricingModel::Volume, $tiers, 200)->minor())->toBe(19_000);
});

it('prices package: ceil(qty / size) whole blocks at the block price', function () {
    // 1000 units per block @ 50.00 per block, size 1000
    $tiers = [tier(null, 0, 5_000)];
    $calc = new TierCalculator;

    // qty 2500 → ceil(2500/1000) = 3 blocks → 3 × 5000 = 15000
    expect($calc->price(PricingModel::Package, $tiers, 2_500, 1_000)->minor())->toBe(15_000)
        // qty 1000 (exact) → 1 block → 5000
        ->and($calc->price(PricingModel::Package, $tiers, 1_000, 1_000)->minor())->toBe(5_000)
        // qty 1001 → 2 blocks → 10000
        ->and($calc->price(PricingModel::Package, $tiers, 1_001, 1_000)->minor())->toBe(10_000)
        // qty 0 → 0 blocks → 0
        ->and($calc->price(PricingModel::Package, $tiers, 0, 1_000)->minor())->toBe(0);
});

it('prices stairstep: one flat amount for the whole bracket the qty lands in', function () {
    // 0–100 → 20.00, 101–500 → 80.00, 501+ → 300.00
    $tiers = [tier(100, 0, 2_000), tier(500, 0, 8_000), tier(null, 0, 30_000)];
    $calc = new TierCalculator;

    // qty 300 → bracket 101–500 → 8000
    expect($calc->price(PricingModel::Stairstep, $tiers, 300)->minor())->toBe(8_000)
        // qty 100 (boundary) → bracket 0–100 → 2000
        ->and($calc->price(PricingModel::Stairstep, $tiers, 100)->minor())->toBe(2_000)
        // qty 5000 → bracket 501+ → 30000
        ->and($calc->price(PricingModel::Stairstep, $tiers, 5_000)->minor())->toBe(30_000);
});

it('denies an empty tier set rather than returning zero', function () {
    (new TierCalculator)->price(PricingModel::Graduated, [], 10);
})->throws(MalformedTierSet::class);

it('denies a non-tiered model', function () {
    (new TierCalculator)->price(PricingModel::PerUnit, standardTiers(), 10);
})->throws(MalformedTierSet::class);

it('denies mis-ordered tier bounds', function () {
    // upTo not strictly ascending (10 then 5)
    (new TierCalculator)->price(PricingModel::Graduated, [tier(10, 100), tier(5, 80)], 3);
})->throws(MalformedTierSet::class);

it('denies an unbounded tier that is not last', function () {
    (new TierCalculator)->price(PricingModel::Graduated, [tier(null, 100), tier(50, 80)], 3);
})->throws(MalformedTierSet::class);

it('denies a quantity no tier covers (last tier bounded)', function () {
    // Volume with a capped final tier and a quantity beyond it → deny, never silent 0.
    (new TierCalculator)->price(PricingModel::Volume, [tier(10, 100), tier(100, 80)], 500);
})->throws(MalformedTierSet::class);

it('denies negative amounts and negative quantities', function () {
    expect(fn () => (new TierCalculator)->price(PricingModel::Graduated, [tier(null, -100)], 5))
        ->toThrow(MalformedTierSet::class)
        ->and(fn () => (new TierCalculator)->price(PricingModel::Graduated, standardTiers(), -1))
        ->toThrow(MalformedTierSet::class);
});

it('denies package pricing without a positive size or a block price', function () {
    expect(fn () => (new TierCalculator)->price(PricingModel::Package, [tier(null, 0, 5_000)], 100, null))
        ->toThrow(MalformedTierSet::class)
        ->and(fn () => (new TierCalculator)->price(PricingModel::Package, [tier(null, 0, 5_000)], 100, 0))
        ->toThrow(MalformedTierSet::class)
        // No flatAmount → no block price configured.
        ->and(fn () => (new TierCalculator)->price(PricingModel::Package, [tier(null, 100)], 100, 10))
        ->toThrow(MalformedTierSet::class);
});

it('prices a tiered quantity through the catalog and Price::amountFor', function () {
    $product = new Product('metered', 'Metered plan');
    $price = new Price(
        'graduated-v1',
        'metered',
        PricingModel::Graduated,
        eurMinor(0),
        new DateTimeImmutable('2025-01-01'),
        tiers: standardTiers(),
    );
    $catalog = new InMemoryCatalog([$product], [$price]);

    // Same 150-unit graduated vector, but resolved end-to-end via the catalog.
    expect($catalog->priceQuantity('metered', 150, new DateTimeImmutable('2025-06-01'))->minor())->toBe(10_700)
        ->and($price->amountFor(150)->minor())->toBe(10_700)
        ->and($catalog->priceQuantity('unknown', 150, new DateTimeImmutable('2025-06-01')))->toBeNull();
});
