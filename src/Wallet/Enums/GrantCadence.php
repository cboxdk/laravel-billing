<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Enums;

use Cbox\Billing\Wallet\Support\CycleGrants;
use DateInterval;
use DateTimeImmutable;

/**
 * How often a plan issues a credit grant (ADR-0013, ADR-0014).
 *
 *  - `Once`   — a one-off grant (a top-up or a single allotment); the whole billing
 *               period is a single slice.
 *  - Everything else is a **recurring** cadence that re-grants (or drips) on its own
 *               period boundary within the billing period:
 *               `Daily`, `Weekly`, `Monthly`, `Quarterly`, `HalfYearly`, `Yearly`.
 *
 * Cadences MIX inside one plan: a daily free-tier reset, a monthly allotment, and a
 * yearly bonus can all be granted at once, each idempotent on its own period
 * ({@see CycleGrants}, ADR-0002).
 *
 * The date math here is the anchor for both distribution (ADR-0014: how many slices
 * a period holds and where their boundaries fall) and per-grant expiry (ADR-0013:
 * `EndOfPeriod` derives a lot's expiry from the cadence period end). Month-based
 * cadences advance from the ORIGINAL anchor with a **month-end clamp** and never
 * drift (Jan 31 → Feb 28/29 → Mar 31, not Feb 28 → Mar 28), and day/week cadences
 * are exact; leap years and 30/31-day months therefore yield the ACTUAL slice count.
 */
enum GrantCadence: string
{
    case Once = 'once';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    /** Does this cadence re-grant on a period boundary (everything but a one-off `Once`)? */
    public function isRecurring(): bool
    {
        return $this !== self::Once;
    }

    /**
     * The `[sliceStart, sliceEnd)` periods of this cadence within `[start, end)`.
     * `Once` yields the single period `[start, end)` (the whole billing period is one
     * slice); a recurring cadence yields one period per boundary strictly before
     * `end`, each ending at the NEXT boundary — so a lot's `EndOfPeriod` expiry is the
     * next boundary and the boundaries never drift.
     *
     * Boundaries are computed from the ORIGINAL `start` (`nth`), never iteratively
     * from a previously-clamped date, so a monthly schedule anchored on the 31st stays
     * on month-ends (Feb 28/29 → Mar 31) without drifting earlier.
     *
     * @return non-empty-list<array{DateTimeImmutable, DateTimeImmutable}>
     */
    public function periods(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($this === self::Once) {
            return [[$start, $end]];
        }

        $periods = [];
        for ($n = 0; ; $n++) {
            $sliceStart = $this->nth($start, $n);
            if ($sliceStart >= $end) {
                break;
            }
            $periods[] = [$sliceStart, $this->nth($start, $n + 1)];
        }

        // A degenerate/empty period still owns at least its opening slice, so a
        // distribution never divides by zero.
        return $periods === [] ? [[$start, $end]] : $periods;
    }

    /**
     * The slice-start boundaries of this cadence within `[start, end)`.
     *
     * @return non-empty-list<DateTimeImmutable>
     */
    public function boundariesWithin(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return array_map(static fn (array $p): DateTimeImmutable => $p[0], $this->periods($start, $end));
    }

    /** How many cadence slices fall within `[start, end)` — the ACTUAL count (leap/month-length aware). */
    public function sliceCount(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return count($this->periods($start, $end));
    }

    /**
     * The `n`-th boundary at or after `$start` for this cadence, anchored on `$start`.
     * Day/week cadences are exact; month-based cadences add whole months to the first
     * of `$start`'s month (which can never overflow) and then clamp the anchor day to
     * the target month's length, preserving the time-of-day.
     */
    private function nth(DateTimeImmutable $start, int $n): DateTimeImmutable
    {
        return match ($this) {
            self::Once => $start,
            self::Daily => $start->add(new DateInterval('P'.$n.'D')),
            self::Weekly => $start->add(new DateInterval('P'.($n * 7).'D')),
            self::Monthly => $this->addMonths($start, $n),
            self::Quarterly => $this->addMonths($start, $n * 3),
            self::HalfYearly => $this->addMonths($start, $n * 6),
            self::Yearly => $this->addMonths($start, $n * 12),
        };
    }

    /** Add `$months` calendar months to `$start`, clamping to the target month's last day. */
    private function addMonths(DateTimeImmutable $start, int $months): DateTimeImmutable
    {
        $anchorDay = (int) $start->format('j');

        // Adding months to the first of the month can never overflow into the next
        // month, so the target year/month is exact.
        $firstOfMonth = $start->setDate((int) $start->format('Y'), (int) $start->format('n'), 1);
        $target = $months >= 0
            ? $firstOfMonth->add(new DateInterval('P'.$months.'M'))
            : $firstOfMonth->sub(new DateInterval('P'.abs($months).'M'));

        $daysInTarget = (int) $target->format('t');
        $day = min($anchorDay, $daysInTarget);

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day);
    }
}
