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
 */
readonly class BillingCycle
{
    public function __construct(
        public int $anchorDay,
        public int $anchorMonth,
        public BillingInterval $interval,
        public DateTimeZone $zone,
    ) {
        if ($anchorDay < 1 || $anchorDay > 31) {
            throw new InvalidArgumentException("Billing anchor day must be 1–31, got {$anchorDay}.");
        }

        if ($anchorMonth < 1 || $anchorMonth > 12) {
            throw new InvalidArgumentException("Billing anchor month must be 1–12, got {$anchorMonth}.");
        }
    }

    /**
     * Anchor the cycle on the subscription's **signup day** (and month, for a yearly
     * cycle) — the default. The signup instant is read in the cycle's zone, so the
     * anchor day is the local day the subscription began.
     */
    public static function anchoredOnSignup(
        DateTimeImmutable $signupAt,
        BillingInterval $interval,
        DateTimeZone $zone = new DateTimeZone('UTC'),
    ): self {
        $local = $signupAt->setTimezone($zone);

        return new self(
            anchorDay: (int) $local->format('j'),
            anchorMonth: (int) $local->format('n'),
            interval: $interval,
            zone: $zone,
        );
    }

    /**
     * Pin the cycle to the **calendar 1st** — every subscription on the plan renews
     * together on the 1st (of January, for a yearly cycle), regardless of signup day.
     */
    public static function calendarFirst(
        BillingInterval $interval,
        DateTimeZone $zone = new DateTimeZone('UTC'),
    ): self {
        return new self(anchorDay: 1, anchorMonth: 1, interval: $interval, zone: $zone);
    }

    /**
     * Build the cycle from an explicit {@see CycleAnchor} choice — the config/plan seam:
     * `SignupDay` reads the anchor from `$signupAt`, `CalendarFirst` pins it to the 1st.
     */
    public static function forAnchor(
        CycleAnchor $anchor,
        DateTimeImmutable $signupAt,
        BillingInterval $interval,
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
