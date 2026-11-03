<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\Enums\CycleAnchor;
use Cbox\Billing\Wallet\Support\MonthMath;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A subscription's billing cycle: an explicit anchor day (1–31) + anchor month, an
 * interval, and a zone (ADR-0012). It is the thing that produces the half-open
 * `[start, end)` {@see BillingPeriod} that proration (ADR-0007) already consumes —
 * so proration derives its `remaining / total` from a real, month-end-clamped cycle
 * rather than an assumed 30-day window.
 *
 * The anchor is stored as an explicit day + zone — never an implicit local time — so
 * a boundary is a determinate instant. The **month-end clamp** is the shared
 * {@see MonthMath} kernel: a 31 anchor bills Jan 31 → Feb 28/29 → Mar 31 → Apr 30, and
 * the intended day (31) is re-applied to every month that has it, so the anchor never
 * drifts permanently earlier after passing through a short month. A `Yearly` cycle
 * additionally pins the anchor **month** (a March-15 signup renews every March 15);
 * a `Monthly` cycle renews on the anchor day of every month and ignores the month.
 *
 * Boundaries are tracked as (year, month) anchor pairs and materialised through the
 * clamp on demand, so the true anchor day is never lost to an intermediate clamped
 * value — the drift the ADR rejects.
 *
 * A cycle runs every `intervalCount` units ({@see BillingFrequency}): a quarterly cycle
 * is three months, keeping the same clamp, so a Jan 31 anchor bills Jan 31 → Apr 30 →
 * Jul 31. A weekly or daily cycle counts whole days from its anchor date on local
 * midnight in the zone, so a daylight-saving change never moves a boundary off midnight.
 * Where the anchor month alone cannot fix the cycle's phase (a day-based cycle, every
 * five months, every two years) the cycle also carries the `anchorYear`; for a plain
 * monthly or yearly cycle it stays null, exactly as before.
 */
readonly class BillingCycle
{
    /** The year a calendar-pinned cycle counts from when its phase needs one: 2001-01-01 is a Monday. */
    private const int CALENDAR_EPOCH_YEAR = 2001;

    public function __construct(
        public int $anchorDay,
        public int $anchorMonth,
        public BillingInterval $interval,
        public DateTimeZone $zone,
        public int $intervalCount = 1,
        public ?int $anchorYear = null,
    ) {
        if ($anchorDay < 1 || $anchorDay > 31) {
            throw new InvalidArgumentException("Billing anchor day must be 1–31, got {$anchorDay}.");
        }

        if ($anchorMonth < 1 || $anchorMonth > 12) {
            throw new InvalidArgumentException("Billing anchor month must be 1–12, got {$anchorMonth}.");
        }

        $frequency = new BillingFrequency($interval, $intervalCount);

        if ($anchorYear === null && self::needsAnchorYear($frequency)) {
            throw new InvalidArgumentException("A billing cycle every {$frequency->key()} needs its anchor year.");
        }
    }

    /** How often this cycle bills: its unit and count, with equal spans folded. */
    public function frequency(): BillingFrequency
    {
        return new BillingFrequency($this->interval, $this->intervalCount);
    }

    /**
     * Anchor the cycle on the subscription's **signup day** (and month, for a yearly
     * cycle) — the default. The signup instant is read in the cycle's zone, so the
     * anchor day is the local day the subscription began.
     */
    public static function anchoredOnSignup(
        DateTimeImmutable $signupAt,
        BillingInterval|BillingFrequency $interval,
        DateTimeZone $zone = new DateTimeZone('UTC'),
    ): self {
        $local = $signupAt->setTimezone($zone);
        $frequency = $interval instanceof BillingFrequency ? $interval : new BillingFrequency($interval);

        return new self(
            anchorDay: (int) $local->format('j'),
            anchorMonth: (int) $local->format('n'),
            interval: $frequency->interval,
            zone: $zone,
            intervalCount: $frequency->count,
            anchorYear: self::needsAnchorYear($frequency) ? (int) $local->format('Y') : null,
        );
    }

    /**
     * Pin the cycle to the **calendar 1st** — every subscription on the plan renews
     * together on the 1st (of January, for a yearly cycle; of January, April, July and
     * October for a quarterly one), regardless of signup day. A weekly cycle renews
     * together on Monday, the ISO week start; a cycle whose phase needs a year counts
     * from January 1, 2001, a Monday.
     */
    public static function calendarFirst(
        BillingInterval|BillingFrequency $interval,
        DateTimeZone $zone = new DateTimeZone('UTC'),
    ): self {
        $frequency = $interval instanceof BillingFrequency ? $interval : new BillingFrequency($interval);

        return new self(
            anchorDay: 1,
            anchorMonth: 1,
            interval: $frequency->interval,
            zone: $zone,
            intervalCount: $frequency->count,
            anchorYear: self::needsAnchorYear($frequency) ? self::CALENDAR_EPOCH_YEAR : null,
        );
    }

    /**
     * Build the cycle from an explicit {@see CycleAnchor} choice — the config/plan seam:
     * `SignupDay` reads the anchor from `$signupAt`, `CalendarFirst` pins it to the 1st.
     */
    public static function forAnchor(
        CycleAnchor $anchor,
        DateTimeImmutable $signupAt,
        BillingInterval|BillingFrequency $interval,
        DateTimeZone $zone = new DateTimeZone('UTC'),
    ): self {
        return match ($anchor) {
            CycleAnchor::SignupDay => self::anchoredOnSignup($signupAt, $interval, $zone),
            CycleAnchor::CalendarFirst => self::calendarFirst($interval, $zone),
        };
    }

    /**
     * The half-open billing period `[start, end)` that contains `$at`. `start` is the
     * anchor boundary at or before `$at`; `end` is the next boundary one interval on.
     * Both are month-end-clamped from the true anchor day.
     */
    public function periodContaining(DateTimeImmutable $at): BillingPeriod
    {
        $local = $at->setTimezone($this->zone);

        if ($this->interval->isDayBased()) {
            return $this->dayPeriod($local);
        }

        if ($this->intervalCount > 1) {
            return $this->multiMonthPeriod($local);
        }

        return $this->interval === BillingInterval::Monthly
            ? $this->monthlyPeriod($local)
            : $this->yearlyPeriod($local);
    }

    /**
     * The period immediately after the one containing `$at` — the next cycle. Handy for
     * a renewal that advances a subscription onto its following period.
     */
    public function nextPeriod(DateTimeImmutable $at): BillingPeriod
    {
        return $this->periodContaining($this->periodContaining($at)->end);
    }

    /** Monthly: the boundary is the anchor day of every month. */
    private function monthlyPeriod(DateTimeImmutable $local): BillingPeriod
    {
        $year = (int) $local->format('Y');
        $month = (int) $local->format('n');

        $start = $this->anchorFor($year, $month);
        if ($start > $local) {
            [$year, $month] = $this->shiftMonths($year, $month, -1);
            $start = $this->anchorFor($year, $month);
        }

        [$endYear, $endMonth] = $this->shiftMonths($year, $month, 1);

        return new BillingPeriod($start, $this->anchorFor($endYear, $endMonth));
    }

    /** Yearly: the boundary is the anchor day of the anchor month, once a year. */
    private function yearlyPeriod(DateTimeImmutable $local): BillingPeriod
    {
        $year = (int) $local->format('Y');

        $start = $this->anchorFor($year, $this->anchorMonth);
        if ($start > $local) {
            $year -= 1;
            $start = $this->anchorFor($year, $this->anchorMonth);
        }

        return new BillingPeriod($start, $this->anchorFor($year + 1, $this->anchorMonth));
    }

    /**
     * Every N months: the boundaries are the anchor day of every N-th month counted from
     * the anchor month (and year, when the phase needs it), each through the clamp.
     */
    private function multiMonthPeriod(DateTimeImmutable $local): BillingPeriod
    {
        $step = $this->frequency()->months();
        $anchorIndex = ($this->anchorYear ?? 0) * 12 + $this->anchorMonth - 1;
        $index = (int) $local->format('Y') * 12 + (int) $local->format('n') - 1;

        $index -= self::modulo($index - $anchorIndex, $step);
        $start = $this->anchorAtIndex($index);
        if ($start > $local) {
            $index -= $step;
            $start = $this->anchorAtIndex($index);
        }

        return new BillingPeriod($start, $this->anchorAtIndex($index + $step));
    }

    /**
     * Every N days or weeks: whole local days counted from the anchor date, so every
     * boundary is local midnight however long the day around a clock change was.
     */
    private function dayPeriod(DateTimeImmutable $local): BillingPeriod
    {
        $step = $this->frequency()->days();
        $anchor = $this->anchorFor($this->anchorYear ?? self::CALENDAR_EPOCH_YEAR, $this->anchorMonth);

        $elapsed = self::dayNumber($local) - self::dayNumber($anchor);
        $offset = $elapsed - self::modulo($elapsed, $step);

        return new BillingPeriod($this->daysAfter($anchor, $offset), $this->daysAfter($anchor, $offset + $step));
    }

    /** Local midnight `$days` calendar days after the anchor date. */
    private function daysAfter(DateTimeImmutable $anchor, int $days): DateTimeImmutable
    {
        return new DateTimeImmutable(
            gmdate('Y-m-d', (self::dayNumber($anchor) + $days) * 86_400).' 00:00:00',
            $this->zone,
        );
    }

    /** The local calendar date as a count of days since 1970-01-01, free of the zone offset. */
    private static function dayNumber(DateTimeImmutable $local): int
    {
        return intdiv((int) gmmktime(0, 0, 0, (int) $local->format('n'), (int) $local->format('j'), (int) $local->format('Y')), 86_400);
    }

    private function anchorAtIndex(int $index): DateTimeImmutable
    {
        return $this->anchorFor(intdiv($index, 12), $index % 12 + 1);
    }

    private static function modulo(int $value, int $divisor): int
    {
        return (($value % $divisor) + $divisor) % $divisor;
    }

    /**
     * Whether the anchor month alone cannot fix the cycle's phase: a day-based cycle, or a
     * month step that does not divide a year (every five months, every two years).
     */
    private static function needsAnchorYear(BillingFrequency $frequency): bool
    {
        return $frequency->isDayBased() || 12 % $frequency->months() !== 0;
    }

    /**
     * The anchor instant in `$year-$month`: midnight in the cycle's zone on the true
     * anchor day clamped to that month's length. Re-clamping from the stored anchor day
     * (never from a previously clamped value) is what keeps a 31 anchor drift-free.
     */
    private function anchorFor(int $year, int $month): DateTimeImmutable
    {
        $day = MonthMath::clampedDay($year, $month, $this->anchorDay);

        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day),
            $this->zone,
        );
    }

    /**
     * Shift a (year, month) pair by `$delta` months with correct borrow/carry, so the
     * anchor month is tracked as an integer index and never a clamped date.
     *
     * @return array{int, int}
     */
    private function shiftMonths(int $year, int $month, int $delta): array
    {
        $index = $year * 12 + ($month - 1) + $delta;

        $newYear = intdiv($index, 12);
        $newMonth = $index % 12;
        if ($newMonth < 0) {
            $newMonth += 12;
            $newYear -= 1;
        }

        return [$newYear, $newMonth + 1];
    }
}
