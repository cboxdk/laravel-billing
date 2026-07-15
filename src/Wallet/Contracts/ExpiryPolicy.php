<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Contracts;

use Cbox\Billing\Wallet\ValueObjects\Duration;
use Cbox\Billing\Wallet\ValueObjects\EndOfPeriod;
use Cbox\Billing\Wallet\ValueObjects\NeverExpires;

/**
 * How a granted lot's `expiresAt` is derived — the knob that turns a recurring grant
 * into either use-it-or-lose-it OR rollover (ADR-0013):
 *
 *  - {@see EndOfPeriod} — the lot dies at the
 *    cadence period end: no rollover, reset each period.
 *  - {@see Duration} — the lot lives a fixed span
 *    from when it was granted: unused credit ROLLS OVER and accumulates, each lot
 *    expiring on its own timer (lot-attributed expiry, ADR-0006).
 *  - {@see NeverExpires} — the lot never expires.
 *
 * Rollover is expiry-beyond-the-period, not a separate mechanism: composing a pool +
 * cadence + expiry policy expresses multi-tier credits (e.g. a monthly pool that
 * resets beside a monthly pool that rolls over a year) in one plan.
 */
interface ExpiryPolicy
{
    /**
     * The `expiresAt` (ms epoch, or `null` = never) for a lot granted at
     * `$grantedAtMs` whose cadence period ends at `$periodEndMs`.
     */
    public function expiresAt(int $grantedAtMs, int $periodEndMs): ?int;
}
