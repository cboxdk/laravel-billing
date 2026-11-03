<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\Enums\PercentageTierMode;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Exceptions\MalformedPercentagePrice;
use Cbox\Billing\Catalog\Pricing\PercentageCalculator;
use Cbox\Billing\Catalog\ValueObjects\PercentagePrice;
use Cbox\Billing\Catalog\ValueObjects\PercentageRate;
use Cbox\Billing\Catalog\ValueObjects\PercentageTier;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Money\Money;

function dkk(int $minor): Money
{
    return Money::ofMinor($minor, 'DKK');
}

function flatRate(string $percent, ?Money $fee = null, ?Money $min = null, ?Money $max = null): PercentagePrice
{
    return new PercentagePrice('DKK', [new PercentageTier(null, PercentageRate::ofPercent($percent))], fixedFee: $fee, minimum: $min, maximum: $max);
}

it('reads a percentage to the millionth and writes it back', function () {
    expect(PercentageRate::ofPercent('1.5')->partsPerMillion)->toBe(15_000)
        ->and(PercentageRate::ofPercent('0.0001')->partsPerMillion)->toBe(1)
        ->and(PercentageRate::ofPercent('100')->partsPerMillion)->toBe(1_000_000)
        ->and(PercentageRate::ofPercent('2.90')->toPercentString())->toBe('2.9')
        ->and(PercentageRate::ofPercent('0')->toPercentString())->toBe('0')
        ->and(PercentageRate::ofPartsPerMillion(14_900)->toPercentString())->toBe('1.49');
});

it('refuses a rate it cannot hold exactly', function (string $percent) {
    PercentageRate::ofPercent($percent);
})->with(['-1', '100.0001', '1.00001', 'abc', '', '1e2'])->throws(MalformedPercentagePrice::class);

it('charges the rate of the volume, rounded once, half up', function () {
    $calc = new PercentageCalculator;

    // 1.5% of 1,234.57 kr = 18.51855 kr → 18.52 kr
    expect($calc->charge(flatRate('1.5'), 123_457)->total->minor())->toBe(1_852)
        // 1.5% of 0.33 kr = 0.495 øre → 0 øre
        ->and($calc->charge(flatRate('1.5'), 33)->total->minor())->toBe(0)
        // 1.5% of 1.00 kr = 1.5 øre → 2 øre (half up)
        ->and($calc->charge(flatRate('1.5'), 100)->total->minor())->toBe(2);
});

it('rounds the line once, never each event', function () {
    // Three events of 0.33 kr each: per event 0.495 øre rounds to 0, three times 0. Once, on 99 øre: 1.485 → 1.
    expect((new PercentageCalculator)->charge(flatRate('1.5'), 99, 3)->total->minor())->toBe(1);
});

it('adds a fixed fee per fee unit, in the same single rounding', function () {
    // 2.9% of 1000.00 kr + 25 events × 1.80 kr = 2900 + 4500 = 7400 øre
    $charge = (new PercentageCalculator)->charge(flatRate('2.9', dkk(180)), 100_000, 25);

    expect($charge->total->minor())->toBe(7_400)
        ->and($charge->fees->minor())->toBe(4_500)
        ->and($charge->bound)->toBeNull();
});

it('lifts a period to its minimum and caps it at its maximum', function () {
    $calc = new PercentageCalculator;
    $price = flatRate('1', min: dkk(5_000), max: dkk(20_000));

    expect($calc->charge($price, 100_000)->total->minor())->toBe(5_000)
        ->and($calc->charge($price, 100_000)->bound)->toBe('minimum')
        ->and($calc->charge($price, 1_000_000)->total->minor())->toBe(10_000)
        ->and($calc->charge($price, 1_000_000)->bound)->toBeNull()
        ->and($calc->charge($price, 5_000_000)->total->minor())->toBe(20_000)
        ->and($calc->charge($price, 5_000_000)->bound)->toBe('maximum');
});

it('charges no minimum for a period with no activity', function () {
    expect((new PercentageCalculator)->charge(flatRate('1', min: dkk(5_000)), 0, 0)->total->isZero())->toBeTrue();
});

it('slices graduated tiers by volume, each slice at its own rate', function () {
    // 0–10,000.00 kr @ 2%, then 1% above. Volume 15,000.00 kr → 200.00 + 50.00 = 250.00 kr
    $price = new PercentagePrice('DKK', [
        new PercentageTier(1_000_000, PercentageRate::ofPercent('2')),
        new PercentageTier(null, PercentageRate::ofPercent('1')),
    ]);

    expect((new PercentageCalculator)->charge($price, 1_500_000)->total->minor())->toBe(25_000)
        ->and((new PercentageCalculator)->charge($price, 1_000_000)->total->minor())->toBe(20_000);
});

it('prices all volume at the tier it lands in for volume tiers', function () {
    $price = new PercentagePrice('DKK', [
        new PercentageTier(1_000_000, PercentageRate::ofPercent('2')),
        new PercentageTier(null, PercentageRate::ofPercent('1')),
    ], PercentageTierMode::Volume);

    expect((new PercentageCalculator)->charge($price, 1_500_000)->total->minor())->toBe(15_000)
        ->and((new PercentageCalculator)->charge($price, 1_000_000)->total->minor())->toBe(20_000);
});

it('rounds graduated slices together, not one by one', function () {
    // 1.5% of 33 øre (0.495) + 1.5% of 33 øre in tier two: one rounding of 0.99 → 1, not 0 + 0.
    $price = new PercentagePrice('DKK', [
        new PercentageTier(33, PercentageRate::ofPercent('1.5')),
        new PercentageTier(null, PercentageRate::ofPercent('1.5')),
    ]);

    expect((new PercentageCalculator)->charge($price, 66)->total->minor())->toBe(1);
});

it('refuses a malformed percentage price', function (Closure $build) {
    $build();
})->with([
    'no tiers' => [fn () => new PercentagePrice('DKK', [])],
    'unbounded tier first' => [fn () => new PercentagePrice('DKK', [new PercentageTier(null, PercentageRate::ofPercent('1')), new PercentageTier(10, PercentageRate::ofPercent('1'))])],
    'descending bounds' => [fn () => new PercentagePrice('DKK', [new PercentageTier(10, PercentageRate::ofPercent('1')), new PercentageTier(5, PercentageRate::ofPercent('1')), new PercentageTier(null, PercentageRate::ofPercent('1'))])],
    'last tier bounded' => [fn () => new PercentagePrice('DKK', [new PercentageTier(10, PercentageRate::ofPercent('1'))])],
    'fee in another currency' => [fn () => new PercentagePrice('DKK', [new PercentageTier(null, PercentageRate::ofPercent('1'))], fixedFee: Money::ofMinor(1, 'EUR'))],
    'negative fee' => [fn () => flatRate('1', dkk(-1))],
    'minimum above maximum' => [fn () => flatRate('1', min: dkk(10), max: dkk(5))],
    'lower-case currency' => [fn () => new PercentagePrice('dkk', [new PercentageTier(null, PercentageRate::ofPercent('1'))])],
])->throws(MalformedPercentagePrice::class);

it('refuses negative volume or fee units', function (int $volume, int $units) {
    (new PercentageCalculator)->charge(flatRate('1'), $volume, $units);
})->with([[-1, 0], [0, -1]])->throws(MalformedPercentagePrice::class);

it('prices a percentage Price through amountFor, the volume as the quantity', function () {
    $price = new Price('p', 'prod', PricingModel::Percentage, dkk(0), new DateTimeImmutable('@0'), percentage: flatRate('1.5'));

    expect($price->amountFor(123_457)->minor())->toBe(1_852)
        ->and(PricingModel::Percentage->isTiered())->toBeFalse()
        ->and(PricingModel::Percentage->isPercentage())->toBeTrue()
        ->and(PricingModel::Graduated->isPercentage())->toBeFalse();
});

it('refuses a percentage Price without its terms', function () {
    (new Price('p', 'prod', PricingModel::Percentage, dkk(0), new DateTimeImmutable('@0')))->amountFor(100);
})->throws(MalformedPercentagePrice::class);

it('refuses terms in another currency than the price', function () {
    (new Price('p', 'prod', PricingModel::Percentage, Money::ofMinor(0, 'EUR'), new DateTimeImmutable('@0'), percentage: flatRate('1')))->amountFor(100);
})->throws(MalformedPercentagePrice::class);

it('holds the largest volume a bigint column can carry without overflow', function () {
    // 100% of PHP_INT_MAX is PHP_INT_MAX; a rounding of that size stays exact.
    expect((new PercentageCalculator)->charge(flatRate('100'), PHP_INT_MAX)->total->minor())->toBe(PHP_INT_MAX);
});
