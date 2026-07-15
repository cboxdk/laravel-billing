<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\ValueObjects\Distributed;
use Cbox\Billing\Wallet\ValueObjects\Fixed;

it('splits an integer total remainder-safe so the parts sum exactly', function (): void {
    // 1,200,000 over 365 parts: 245 parts of 3288, 120 of 3287 — sums to exactly 1.2M.
    $parts = Money::allocate(1_200_000, 365);

    expect($parts)->toHaveCount(365)
        ->and(array_sum($parts))->toBe(1_200_000)
        ->and(max($parts) - min($parts))->toBe(1)   // parts differ by at most one unit
        ->and(count(array_filter($parts, fn (int $p): bool => $p === 3288)))->toBe(245)
        ->and(count(array_filter($parts, fn (int $p): bool => $p === 3287)))->toBe(120);
});

it('allocates an exactly-divisible total into equal parts', function (): void {
    expect(Money::allocate(1_200_000, 12))->toBe(array_fill(0, 12, 100_000));
});

it('counts the actual cadence slices in a period (leap-year aware)', function (): void {
    $leapYear = [new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2025-01-01')];
    $commonYear = [new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2026-01-01')];

    expect(GrantCadence::Daily->sliceCount(...$leapYear))->toBe(366)
        ->and(GrantCadence::Daily->sliceCount(...$commonYear))->toBe(365)
        ->and(GrantCadence::Monthly->sliceCount(...$commonYear))->toBe(12)
        ->and(GrantCadence::Quarterly->sliceCount(...$commonYear))->toBe(4)
        ->and(GrantCadence::Weekly->sliceCount(...$commonYear))->toBe(53)
        ->and(GrantCadence::Once->sliceCount(...$commonYear))->toBe(1);
});

it('distributes a yearly total across every actual day, summing to exactly the total', function (): void {
    $start = new DateTimeImmutable('2024-01-01'); // leap year → 366 days
    $end = new DateTimeImmutable('2025-01-01');

    $slices = (new Distributed(1_200_000, GrantCadence::Daily))->slices($start, $end);
    $amounts = array_map(fn ($s) => $s->amount, $slices);

    expect($slices)->toHaveCount(366)
        ->and(array_sum($amounts))->toBe(1_200_000);
});

it('anchors a monthly schedule on the last day without drifting (Jan 31 → Feb 29 → Mar 31)', function (): void {
    $start = new DateTimeImmutable('2024-01-31 00:00:00'); // leap year
    $end = new DateTimeImmutable('2024-04-01 00:00:00');

    $dates = array_map(
        fn ($s): string => gmdate('Y-m-d', intdiv($s->boundaryMs, 1000)),
        (new Fixed(100, GrantCadence::Monthly))->slices($start, $end),
    );

    // Clamped to each month's last day, anchored back on the 31st — no earlier drift.
    expect($dates)->toBe(['2024-01-31', '2024-02-29', '2024-03-31']);
});

it('fixes the same amount at every cadence boundary', function (): void {
    $slices = (new Fixed(1_000, GrantCadence::Monthly))->slices(
        new DateTimeImmutable('2025-01-01'),
        new DateTimeImmutable('2025-04-01'),
    );

    expect($slices)->toHaveCount(3)
        ->and(array_map(fn ($s) => $s->amount, $slices))->toBe([1_000, 1_000, 1_000]);
});
