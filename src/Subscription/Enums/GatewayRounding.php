<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

use Brick\Math\RoundingMode;

/**
 * How a single proration line is rounded to whole minor units, aligned to the
 * settlement gateway that will actually charge it. The gateway rounds each invoice
 * line independently; matching its mode line-by-line is what keeps a preview equal
 * to the charge to the cent. Rounding a combined total instead diverges from the
 * gateway by a cent or two.
 */
enum GatewayRounding: string
{
    case HalfUp = 'half_up';
    case HalfEven = 'half_even';
    case Up = 'up';
    case Down = 'down';

    /** The brick/math rounding mode this maps to — the interop seam with the money primitive. */
    public function mode(): RoundingMode
    {
        return match ($this) {
            self::HalfUp => RoundingMode::HalfUp,
            self::HalfEven => RoundingMode::HalfEven,
            self::Up => RoundingMode::Up,
            self::Down => RoundingMode::Down,
        };
    }
}
