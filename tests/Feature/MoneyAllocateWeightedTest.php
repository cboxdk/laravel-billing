<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;

it('distributes the remainder to the earliest parts on an equal-weight split (100 over [1,1,1])', function (): void {
    // The documented largest-remainder vector: 100 across three equal weights is
    // 33.33 each; the one leftover unit goes to the EARLIEST part (tie-break by index).
    $parts = Money::allocateWeighted(100, [1, 1, 1]);

    expect($parts)->toBe([34, 33, 33])
        ->and(array_sum($parts))->toBe(100);
});

it('splits a real tax amount across line weights, remainder-safe and summing exactly', function (): void {
    // A 25% VAT of 2500 minor units on a 100.00 order, split across three lines whose
    // net amounts are 3300 / 3300 / 3400. Exact shares are 825 / 825 / 850 — no unit
    // stranded, and the parts sum back to the exact tax total.
    $parts = Money::allocateWeighted(2500, [3300, 3300, 3400]);

    expect($parts)->toBe([825, 825, 850])
        ->and(array_sum($parts))->toBe(2500);
});

it('splits a tax amount that leaves a remainder to the earliest, largest-remainder parts', function (): void {
    // 1000 across net weights 1/1/1 → 334/333/333; the leftover cent lands on line 0.
    $parts = Money::allocateWeighted(1000, [1, 1, 1]);

    expect($parts)->toBe([334, 333, 333])
        ->and(array_sum($parts))->toBe(1000);
});

it('honours weight proportions when distributing the leftover unit', function (): void {
    // 10 across weights 1/2/3 (Σ=6): exact shares 1.66 / 3.33 / 5.00 → floors 1/3/5 = 9,
    // one leftover unit to the largest remainder (part 0, remainder 4/6) → 2/3/5.
    $parts = Money::allocateWeighted(10, [1, 2, 3]);

    expect($parts)->toBe([2, 3, 5])
        ->and(array_sum($parts))->toBe(10);
});

it('distributes a negative total identically, keeping every unit', function (): void {
    $parts = Money::allocateWeighted(-100, [1, 1, 1]);

    expect($parts)->toBe([-34, -33, -33])
        ->and(array_sum($parts))->toBe(-100);
});

it('returns an empty allocation for no weights', function (): void {
    expect(Money::allocateWeighted(100, []))->toBe([]);
});

it('rejects a negative weight', function (): void {
    expect(fn () => Money::allocateWeighted(100, [1, -1]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects all-zero weights', function (): void {
    expect(fn () => Money::allocateWeighted(100, [0, 0]))
        ->toThrow(InvalidArgumentException::class);
});
