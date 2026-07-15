<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Support;

use Cbox\Billing\Subscription\ValueObjects\BillingCycle;
use DateInterval;
use DateTimeImmutable;

/**
 * The month-end clamp kernel shared by every calendar advance in the engine
 * (ADR-0012, ADR-0013, ADR-0014). A month-based schedule anchored on a day that some
 * target month does not have — the 31st in February, the 30th in February, the 29th
 * in a common year — clamps to that month's **last day**, and the intended anchor day
 * is preserved for months that do have it, so the boundary never drifts permanently
 * earlier (Jan 31 → Feb 28/29 → Mar 31, never Feb 28 → Mar 28).
 *
 * {@see GrantCadence} advances grant slices from an original anchor date; the billing
 * {@see BillingCycle} clamps an explicit
 * anchor day into a target year/month. Both derive from the same {@see clampedDay()}
 * primitive so the two can never disagree on where a boundary falls.
 */
class MonthMath
{
    /**
     * The anchor day clamped to `$year-$month`'s length: `min($anchorDay, days in that
     * month)`. Leap-year aware (February is 29 in a leap year, 28 otherwise). Zone
     * plays no part — a month's length is the same in every zone.
     */
    public static function clampedDay(int $year, int $month, int $anchorDay): int
    {
        $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        return min($anchorDay, $daysInMonth);
    }

    /**
     * Add `$months` calendar months to `$from`, preserving its anchor day-of-month with
     * a month-end clamp and keeping its time-of-day. The anchor day is read from `$from`
     * and re-applied to the target month, so advancing from an already-clamped date
     * (Feb 28) still targets the true anchor day where the month allows it (Mar 31).
     *
     * Adding to the FIRST of the month can never overflow into the next month, so the
     * target year/month is exact before the day is clamped back on.
     */
    public static function addMonths(DateTimeImmutable $from, int $months): DateTimeImmutable
    {
        $anchorDay = (int) $from->format('j');

        $firstOfMonth = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), 1);
        $target = $months >= 0
            ? $firstOfMonth->add(new DateInterval('P'.$months.'M'))
            : $firstOfMonth->sub(new DateInterval('P'.abs($months).'M'));

        $day = self::clampedDay((int) $target->format('Y'), (int) $target->format('n'), $anchorDay);

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day);
    }
}
