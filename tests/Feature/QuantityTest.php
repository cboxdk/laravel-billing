<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Exceptions\InvalidQuantity;
use Cbox\Billing\Quote\ValueObjects\Quantity;

it('parses decimal strings and ints to thousandths', function (string|int $in, int $scaled, string $out): void {
    $q = Quantity::of($in);
    expect($q->scaled())->toBe($scaled)->and($q->toDecimalString())->toBe($out);
})->with([['12.5', 12500, '12.500'], ['12.500', 12500, '12.500'], [7, 7000, '7.000'], ['0.001', 1, '0.001'], ['999999.999', 999_999_999, '999999.999']]);

it('refuses what is not a positive quantity with at most three decimals', function (string|int $in, string $factory): void {
    expect(fn () => Quantity::of($in))->toThrow(InvalidQuantity::class);
})->with([['0', 'nonPositive'], ['0.000', 'nonPositive'], ['-1', 'nonPositive'], ['0.0004', 'tooPrecise'], ['1.2345', 'tooPrecise'], ['1,5', 'malformed'], ['1e3', 'malformed'], ['', 'malformed'], ['1000000', 'tooLarge']]);

it('knows whole quantities and refuses toInt on fractions', function (): void {
    expect(Quantity::of('3')->isWhole())->toBeTrue()
        ->and(Quantity::of('3')->toInt())->toBe(3)
        ->and(Quantity::of('3.5')->isWhole())->toBeFalse()
        ->and(fn () => Quantity::of('3.5')->toInt())->toThrow(InvalidQuantity::class);
});

it('multiplies money by a quantity rounding half up once per line', function (string $qty, int $unit, string $cur, int $expected): void {
    expect(Money::ofMinor($unit, $cur)->times(Quantity::of($qty))->minor())->toBe($expected);
})->with([
    ['0.950', 120000, 'DKK', 114000],
    ['1.125', 100000, 'DKK', 112500],
    ['0.333', 10000, 'DKK', 3330],
    ['2.5', 333, 'DKK', 833],      // 832.5 → 833
    ['2.5', 333, 'ISK', 833],      // zero-decimal currency: minor == whole units
    ['3', 5000, 'EUR', 15000],
]);
