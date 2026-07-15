<?php

declare(strict_types=1);

use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\Enums\CycleAnchor;
use Cbox\Billing\Subscription\ValueObjects\BillingCycle;

/** The [start,end) dates a cycle produces for an instant, formatted for readable assertions. */
function cyclePeriod(BillingCycle $cycle, string $at): array
{
    $period = $cycle->periodContaining(new DateTimeImmutable($at, new DateTimeZone('UTC')));

    return [$period->start->format('Y-m-d'), $period->end->format('Y-m-d')];
}

it('clamps a 31 anchor across short months without drifting earlier (Jan 31 → Feb 29 → Mar 31 → Apr 30)', function (): void {
    $cycle = new BillingCycle(31, 1, BillingInterval::Monthly, new DateTimeZone('UTC'));

    // Leap year: the intended 31st is preserved in every long month; Feb clamps to 29.
    expect(cyclePeriod($cycle, '2024-01-31 12:00:00'))->toBe(['2024-01-31', '2024-02-29'])
        ->and(cyclePeriod($cycle, '2024-02-15'))->toBe(['2024-01-31', '2024-02-29'])
        ->and(cyclePeriod($cycle, '2024-03-01'))->toBe(['2024-02-29', '2024-03-31'])
        ->and(cyclePeriod($cycle, '2024-04-10'))->toBe(['2024-03-31', '2024-04-30']);
});

it('clamps a 31 anchor to Feb 28 in a common year', function (): void {
    $cycle = new BillingCycle(31, 1, BillingInterval::Monthly, new DateTimeZone('UTC'));

    expect(cyclePeriod($cycle, '2025-02-10'))->toBe(['2025-01-31', '2025-02-28'])
        ->and(cyclePeriod($cycle, '2025-03-01'))->toBe(['2025-02-28', '2025-03-31']);
});

it('anchors a signup-day cycle on the day the subscription began', function (): void {
    $signup = new DateTimeImmutable('2025-09-16 09:30:00', new DateTimeZone('UTC'));
    $cycle = BillingCycle::anchoredOnSignup($signup, BillingInterval::Monthly);

    expect($cycle->anchorDay)->toBe(16)
        ->and(cyclePeriod($cycle, '2025-09-20'))->toBe(['2025-09-16', '2025-10-16'])
        ->and(cyclePeriod($cycle, '2025-09-10'))->toBe(['2025-08-16', '2025-09-16']);
});

it('pins a calendar-first cycle to the 1st regardless of signup day', function (): void {
    $signup = new DateTimeImmutable('2025-09-16 09:30:00', new DateTimeZone('UTC'));

    $signupCycle = BillingCycle::forAnchor(CycleAnchor::SignupDay, $signup, BillingInterval::Monthly);
    $calendarCycle = BillingCycle::forAnchor(CycleAnchor::CalendarFirst, $signup, BillingInterval::Monthly);

    // Same signup, two anchor modes → two different periods for the same instant.
    expect(cyclePeriod($signupCycle, '2025-09-16'))->toBe(['2025-09-16', '2025-10-16'])
        ->and(cyclePeriod($calendarCycle, '2025-09-16'))->toBe(['2025-09-01', '2025-10-01'])
        ->and($calendarCycle->anchorDay)->toBe(1);
});

it('runs a yearly cycle on the signup month + day, clamping a Feb-29 anchor in common years', function (): void {
    $leapSignup = new DateTimeImmutable('2024-02-29 00:00:00', new DateTimeZone('UTC'));
    $cycle = BillingCycle::anchoredOnSignup($leapSignup, BillingInterval::Yearly);

    expect($cycle->anchorDay)->toBe(29)
        ->and($cycle->anchorMonth)->toBe(2)
        // 2025 is common → the Feb-29 anchor clamps to Feb 28, then returns to 29 in 2028 (no drift).
        ->and(cyclePeriod($cycle, '2025-06-01'))->toBe(['2025-02-28', '2026-02-28'])
        ->and(cyclePeriod($cycle, '2024-06-01'))->toBe(['2024-02-29', '2025-02-28']);
});

it('produces the day-based BillingPeriod proration consumes', function (): void {
    $cycle = BillingCycle::calendarFirst(BillingInterval::Monthly);
    $period = $cycle->periodContaining(new DateTimeImmutable('2025-09-16', new DateTimeZone('UTC')));

    // A real calendar month, not an assumed 30 — September is 30, its mid-point leaves 15.
    expect($period->totalDays())->toBe(30)
        ->and($period->remainingDays(new DateTimeImmutable('2025-09-16', new DateTimeZone('UTC'))))->toBe(15);
});

it('advances onto the next cycle at renewal', function (): void {
    $cycle = new BillingCycle(31, 1, BillingInterval::Monthly, new DateTimeZone('UTC'));

    $next = $cycle->nextPeriod(new DateTimeImmutable('2024-01-31 12:00:00', new DateTimeZone('UTC')));

    // The cycle after [Jan 31, Feb 29) is [Feb 29, Mar 31).
    expect([$next->start->format('Y-m-d'), $next->end->format('Y-m-d')])->toBe(['2024-02-29', '2024-03-31']);
});

it('rejects an out-of-range anchor day or month', function (): void {
    expect(fn () => new BillingCycle(32, 1, BillingInterval::Monthly, new DateTimeZone('UTC')))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new BillingCycle(15, 13, BillingInterval::Yearly, new DateTimeZone('UTC')))
        ->toThrow(InvalidArgumentException::class);
});
