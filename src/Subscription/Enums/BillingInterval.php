<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

use Cbox\Billing\Wallet\Enums\GrantCadence;

/**
 * How long a billing cycle runs before it renews (ADR-0012). A `Monthly` cycle renews
 * every month on the anchor day; a `Yearly` cycle renews every year on the anchor
 * month + day. Both advance through the same month-end clamp, so a 31 anchor is
 * preserved across short months.
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /** The number of calendar months one cycle spans. */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
        };
    }

    /** The equivalent grant cadence, so a plan's allotment can drip on the cycle boundary. */
    public function cadence(): GrantCadence
    {
        return match ($this) {
            self::Monthly => GrantCadence::Monthly,
            self::Yearly => GrantCadence::Yearly,
        };
    }
}
