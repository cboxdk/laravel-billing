<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

use Cbox\Billing\Subscription\ValueObjects\BillingFrequency;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use LogicException;

/**
 * The unit a billing cycle counts in (ADR-0012). A `Monthly` cycle renews on the anchor
 * day of every month; a `Yearly` cycle renews every year on the anchor month + day. Both
 * advance through the same month-end clamp, so a 31 anchor is preserved across short
 * months. A `Weekly` or `Daily` cycle counts whole days from its anchor date, on local
 * midnight in the cycle's zone.
 *
 * A cycle runs every N of these units: {@see BillingFrequency} pairs the unit with the
 * count, so a quarter is three `Monthly` units and a fortnight two `Weekly` ones.
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Weekly = 'weekly';
    case Daily = 'daily';

    /** Does this unit count whole days rather than calendar months? */
    public function isDayBased(): bool
    {
        return $this === self::Weekly || $this === self::Daily;
    }

    /**
     * The number of calendar months one unit spans.
     *
     * @throws LogicException for a day-based unit, which has no whole number of months
     */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
            self::Weekly, self::Daily => throw new LogicException("A {$this->value} interval does not span whole months."),
        };
    }

    /**
     * The number of days one unit spans.
     *
     * @throws LogicException for a month-based unit, whose length in days varies
     */
    public function days(): int
    {
        return match ($this) {
            self::Weekly => 7,
            self::Daily => 1,
            self::Monthly, self::Yearly => throw new LogicException("A {$this->value} interval does not span a fixed number of days."),
        };
    }

    /** The equivalent grant cadence, so a plan's allotment can drip on the cycle boundary. */
    public function cadence(): GrantCadence
    {
        return match ($this) {
            self::Monthly => GrantCadence::Monthly,
            self::Yearly => GrantCadence::Yearly,
            self::Weekly => GrantCadence::Weekly,
            self::Daily => GrantCadence::Daily,
        };
    }
}
