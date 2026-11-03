<?php

declare(strict_types=1);

use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\Enums\CycleAnchor;
use Cbox\Billing\Subscription\ValueObjects\BillingCycle;
use Cbox\Billing\Subscription\ValueObjects\BillingFrequency;
use Cbox\Billing\Wallet\Enums\GrantCadence;

/** The [start,end) dates a cycle produces for an instant, formatted for readable assertions. */
function frequencyPeriod(BillingCycle $cycle, string $at, string $zone = 'UTC'): array
{
    $period = $cycle->periodContaining(new DateTimeImmutable($at, new DateTimeZone($zone)));

    return [$period->start->format('Y-m-d'), $period->end->format('Y-m-d')];
}

it('names every frequency by one canonical key', function (string $key, BillingInterval $interval, int $count): void {
    $frequency = BillingFrequency::fromKey($key);

    expect($frequency->interval)->toBe($interval)
        ->and($frequency->count)->toBe($count);
})->with([
    'month' => ['month', BillingInterval::Monthly, 1],
    'monthly' => ['monthly', BillingInterval::Monthly, 1],
    'year' => ['year', BillingInterval::Yearly, 1],
    'yearly' => ['yearly', BillingInterval::Yearly, 1],
    'annual' => ['annual', BillingInterval::Yearly, 1],
    'week' => ['week', BillingInterval::Weekly, 1],
    'weekly' => ['weekly', BillingInterval::Weekly, 1],
    'day' => ['day', BillingInterval::Daily, 1],
    'daily' => ['daily', BillingInterval::Daily, 1],
    'quarter' => ['quarter', BillingInterval::Monthly, 3],
    'quarterly' => ['quarterly', BillingInterval::Monthly, 3],
    'semi-annual' => ['semi_annual', BillingInterval::Monthly, 6],
    'half-yearly' => ['half_yearly', BillingInterval::Monthly, 6],
    '2-week' => ['2-week', BillingInterval::Weekly, 2],
    '3-month' => ['3-month', BillingInterval::Monthly, 3],
    '10-day' => ['10-day', BillingInterval::Daily, 10],
    '2-year' => ['2-year', BillingInterval::Yearly, 2],
]);

it('folds equal spans onto one key, so the same cadence is never priced twice', function (): void {
    expect(BillingFrequency::fromKey('12-month')->key())->toBe('year')
        ->and(BillingFrequency::fromKey('24-month')->key())->toBe('2-year')
        ->and(BillingFrequency::fromKey('7-day')->key())->toBe('week')
        ->and(BillingFrequency::fromKey('14-day')->key())->toBe('2-week')
        ->and(BillingFrequency::fromKey('1-month')->key())->toBe('month')
        ->and(BillingFrequency::quarterly()->key())->toBe('3-month')
        ->and(BillingFrequency::semiAnnually()->key())->toBe('6-month')
        ->and(BillingFrequency::of(BillingInterval::Weekly, 2)->key())->toBe('2-week');
});

it('refuses a key it does not know, a zero count, or a span past three years', function (string $key): void {
    expect(BillingFrequency::tryFromKey($key))->toBeNull()
        ->and(fn () => BillingFrequency::fromKey($key))->toThrow(InvalidArgumentException::class);
})->with(['fortnight', '0-month', '-1-week', '37-month', '4-year', '157-week', '1096-day', '', '3-months', 'month-3']);

it('knows its length in months or days, never both', function (): void {
    expect(BillingFrequency::quarterly()->months())->toBe(3)
        ->and(BillingFrequency::of(BillingInterval::Yearly, 2)->months())->toBe(24)
        ->and(BillingFrequency::of(BillingInterval::Weekly, 2)->days())->toBe(14)
        ->and(BillingFrequency::of(BillingInterval::Daily, 10)->days())->toBe(10)
        ->and(BillingFrequency::weekly()->isDayBased())->toBeTrue()
        ->and(BillingFrequency::monthly()->isDayBased())->toBeFalse()
        ->and(fn () => BillingFrequency::weekly()->months())->toThrow(LogicException::class)
        ->and(fn () => BillingFrequency::monthly()->days())->toThrow(LogicException::class);
});

it('maps onto the grant cadence that drips on the same boundary, when one exists', function (): void {
    expect(BillingFrequency::monthly()->cadence())->toBe(GrantCadence::Monthly)
        ->and(BillingFrequency::yearly()->cadence())->toBe(GrantCadence::Yearly)
        ->and(BillingFrequency::quarterly()->cadence())->toBe(GrantCadence::Quarterly)
        ->and(BillingFrequency::semiAnnually()->cadence())->toBe(GrantCadence::HalfYearly)
        ->and(BillingFrequency::weekly()->cadence())->toBe(GrantCadence::Weekly)
        ->and(BillingFrequency::daily()->cadence())->toBe(GrantCadence::Daily)
        ->and(BillingFrequency::of(BillingInterval::Weekly, 2)->cadence())->toBeNull()
        ->and(BillingFrequency::of(BillingInterval::Monthly, 5)->cadence())->toBeNull();
});

it('states its share of a month as an exact fraction', function (): void {
    expect(BillingFrequency::monthly()->monthlyShare())->toBe([1, 1])
        ->and(BillingFrequency::yearly()->monthlyShare())->toBe([1, 12])
        ->and(BillingFrequency::quarterly()->monthlyShare())->toBe([1, 3])
        // 146097 days in 400 Gregorian years over 4800 months: a week is 146097 / 33600 of a month.
        ->and(BillingFrequency::weekly()->monthlyShare())->toBe([146097, 33600])
        ->and(BillingFrequency::of(BillingInterval::Daily, 10)->monthlyShare())->toBe([146097, 48000]);
});

it('orders frequencies from shortest to longest', function (): void {
    $keys = ['year', 'month', '2-week', 'day', '6-month', 'week', '3-month', '2-year'];
    usort($keys, static fn (string $a, string $b): int => BillingFrequency::fromKey($a)->compare(BillingFrequency::fromKey($b)));

    expect($keys)->toBe(['day', 'week', '2-week', 'month', '3-month', '6-month', 'year', '2-year']);
});

it('runs a weekly cycle on the signup weekday', function (): void {
    // 2025-09-16 is a Tuesday.
    $cycle = BillingCycle::anchoredOnSignup(new DateTimeImmutable('2025-09-16 09:30:00', new DateTimeZone('UTC')), BillingInterval::Weekly);

    expect(frequencyPeriod($cycle, '2025-09-16 09:30:00'))->toBe(['2025-09-16', '2025-09-23'])
        ->and(frequencyPeriod($cycle, '2025-09-22 23:59:59'))->toBe(['2025-09-16', '2025-09-23'])
        ->and(frequencyPeriod($cycle, '2025-09-23'))->toBe(['2025-09-23', '2025-09-30'])
        ->and(frequencyPeriod($cycle, '2025-09-10'))->toBe(['2025-09-09', '2025-09-16'])
        ->and($cycle->frequency()->key())->toBe('week');
});

it('runs every two weeks from the anchor, across a month and a year end', function (): void {
    $cycle = BillingCycle::anchoredOnSignup(new DateTimeImmutable('2025-12-24', new DateTimeZone('UTC')), BillingFrequency::of(BillingInterval::Weekly, 2));

    expect(frequencyPeriod($cycle, '2025-12-30'))->toBe(['2025-12-24', '2026-01-07'])
        ->and(frequencyPeriod($cycle, '2026-01-07'))->toBe(['2026-01-07', '2026-01-21'])
        ->and(frequencyPeriod($cycle, '2026-03-01'))->toBe(['2026-02-18', '2026-03-04'])
        ->and($cycle->nextPeriod(new DateTimeImmutable('2025-12-24', new DateTimeZone('UTC')))->start->format('Y-m-d'))->toBe('2026-01-07');
});

it('runs every N days and counts the real days of the period', function (): void {
    $cycle = BillingCycle::anchoredOnSignup(new DateTimeImmutable('2024-02-25', new DateTimeZone('UTC')), BillingFrequency::of(BillingInterval::Daily, 10));
    $period = $cycle->periodContaining(new DateTimeImmutable('2024-03-01', new DateTimeZone('UTC')));

    // Leap year: Feb 25 + 10 days is Mar 6.
    expect([$period->start->format('Y-m-d'), $period->end->format('Y-m-d')])->toBe(['2024-02-25', '2024-03-06'])
        ->and($period->totalDays())->toBe(10);
});

it('keeps a weekly boundary on local midnight across a daylight-saving change', function (): void {
    $zone = new DateTimeZone('Europe/Copenhagen');
    // Clocks go forward on 2025-03-30.
    $cycle = BillingCycle::anchoredOnSignup(new DateTimeImmutable('2025-03-24 10:00', $zone), BillingInterval::Weekly, $zone);
    $period = $cycle->periodContaining(new DateTimeImmutable('2025-04-02 12:00', $zone));

    expect($period->start->format('Y-m-d H:i'))->toBe('2025-03-31 00:00')
        ->and($period->end->format('Y-m-d H:i'))->toBe('2025-04-07 00:00');
});

it('runs a quarterly cycle from the signup month, keeping a 31 anchor at month end', function (): void {
    $cycle = BillingCycle::anchoredOnSignup(new DateTimeImmutable('2025-01-31', new DateTimeZone('UTC')), BillingFrequency::quarterly());

    expect(frequencyPeriod($cycle, '2025-02-15'))->toBe(['2025-01-31', '2025-04-30'])
        ->and(frequencyPeriod($cycle, '2025-04-30'))->toBe(['2025-04-30', '2025-07-31'])
        ->and(frequencyPeriod($cycle, '2025-10-31'))->toBe(['2025-10-31', '2026-01-31'])
        ->and(frequencyPeriod($cycle, '2025-01-15'))->toBe(['2024-10-31', '2025-01-31']);
});

it('runs a semi-annual cycle twice a year on the anchor months', function (): void {
    $cycle = BillingCycle::anchoredOnSignup(new DateTimeImmutable('2025-08-29', new DateTimeZone('UTC')), BillingFrequency::semiAnnually());

    expect(frequencyPeriod($cycle, '2025-09-01'))->toBe(['2025-08-29', '2026-02-28'])
        ->and(frequencyPeriod($cycle, '2026-03-01'))->toBe(['2026-02-28', '2026-08-29'])
        ->and(frequencyPeriod($cycle, '2028-02-29'))->toBe(['2028-02-29', '2028-08-29']);
});

it('runs every two years and every five months from the anchor year', function (): void {
    $biennial = BillingCycle::anchoredOnSignup(new DateTimeImmutable('2025-03-15', new DateTimeZone('UTC')), BillingFrequency::of(BillingInterval::Yearly, 2));
    $fiveMonthly = BillingCycle::anchoredOnSignup(new DateTimeImmutable('2025-01-10', new DateTimeZone('UTC')), BillingFrequency::of(BillingInterval::Monthly, 5));

    expect($biennial->anchorYear)->toBe(2025)
        ->and(frequencyPeriod($biennial, '2026-06-01'))->toBe(['2025-03-15', '2027-03-15'])
        ->and(frequencyPeriod($biennial, '2027-03-15'))->toBe(['2027-03-15', '2029-03-15'])
        ->and(frequencyPeriod($biennial, '2025-01-01'))->toBe(['2023-03-15', '2025-03-15'])
        ->and(frequencyPeriod($fiveMonthly, '2025-07-01'))->toBe(['2025-06-10', '2025-11-10'])
        ->and(frequencyPeriod($fiveMonthly, '2026-01-01'))->toBe(['2025-11-10', '2026-04-10']);
});

it('leaves a monthly or yearly cycle exactly as it was', function (): void {
    $signup = new DateTimeImmutable('2025-09-16 09:30:00', new DateTimeZone('UTC'));

    expect(BillingCycle::anchoredOnSignup($signup, BillingInterval::Monthly))
        ->toEqual(new BillingCycle(16, 9, BillingInterval::Monthly, new DateTimeZone('UTC')))
        ->and(BillingCycle::anchoredOnSignup($signup, BillingFrequency::monthly()))
        ->toEqual(new BillingCycle(16, 9, BillingInterval::Monthly, new DateTimeZone('UTC')))
        ->and(BillingCycle::anchoredOnSignup($signup, BillingInterval::Yearly))
        ->toEqual(new BillingCycle(16, 9, BillingInterval::Yearly, new DateTimeZone('UTC')))
        ->and((new BillingCycle(16, 9, BillingInterval::Monthly, new DateTimeZone('UTC')))->frequency())
        ->toEqual(BillingFrequency::monthly());
});

it('pins a calendar-first cycle to calendar quarters and to ISO weeks', function (): void {
    $quarters = BillingCycle::calendarFirst(BillingFrequency::quarterly());
    $weeks = BillingCycle::forAnchor(CycleAnchor::CalendarFirst, new DateTimeImmutable('2025-09-17'), BillingInterval::Weekly);

    // 2025-09-15 is a Monday.
    expect(frequencyPeriod($quarters, '2025-05-20'))->toBe(['2025-04-01', '2025-07-01'])
        ->and(frequencyPeriod($weeks, '2025-09-17'))->toBe(['2025-09-15', '2025-09-22']);
});

it('needs the anchor year for a cycle whose phase the month alone cannot fix', function (): void {
    expect(fn () => new BillingCycle(10, 1, BillingInterval::Weekly, new DateTimeZone('UTC')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BillingCycle(10, 1, BillingInterval::Monthly, new DateTimeZone('UTC'), intervalCount: 5))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BillingCycle(10, 1, BillingInterval::Monthly, new DateTimeZone('UTC'), intervalCount: 0))
        ->toThrow(InvalidArgumentException::class)
        ->and((new BillingCycle(10, 1, BillingInterval::Monthly, new DateTimeZone('UTC'), intervalCount: 3))->frequency()->key())
        ->toBe('3-month');
});
