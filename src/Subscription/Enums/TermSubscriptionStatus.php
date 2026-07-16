<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Subscription\ValueObjects\RegistrarWindows;

/**
 * The registrar-style lifecycle state of a fixed-term instance (ADR-0015):
 *
 *  - `Active`        — within the committed term (or auto-renewing): entitled and billable.
 *  - `Grace`         — past term end, inside the grace window: still recoverable by a
 *                      renewal at the {@see PriceKind::Renewal} price.
 *  - `Redemption`    — past the grace window, inside the redemption window: recoverable only
 *                      by a redeem at the {@see PriceKind::Redemption} price.
 *  - `Expired`       — past grace + redemption: gone, the instance must be re-registered.
 *  - `TransferredOut`— moved to another provider (transfer-out); terminal here.
 *  - `Cancelled`     — cancelled by the holder; terminal.
 *
 * `Grace`, `Redemption`, and `Expired` are *phases* computed from the term end and the
 * {@see RegistrarWindows}; `TransferredOut` and
 * `Cancelled` are settled terminal states that the phase computation preserves.
 */
enum TermSubscriptionStatus: string
{
    case Active = 'active';
    case Grace = 'grace';
    case Redemption = 'redemption';
    case Expired = 'expired';
    case TransferredOut = 'transferred_out';
    case Cancelled = 'cancelled';

    /** A settled terminal state that phase computation must not recompute away. */
    public function isTerminal(): bool
    {
        return $this === self::TransferredOut || $this === self::Cancelled;
    }
}
