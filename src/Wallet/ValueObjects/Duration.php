<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Wallet\Contracts\ExpiryPolicy;
use InvalidArgumentException;

/**
 * Rollover: each lot lives a fixed number of seconds from when it was granted,
 * independent of the cadence period. Unused credit accumulates across periods, each
 * lot ageing out on its own timer (lot-attributed expiry, ADR-0006) — e.g. Monthly +
 * `Duration(1 year)` rolls each month's grant over and expires it a year after that
 * grant (ADR-0013).
 */
readonly class Duration implements ExpiryPolicy
{
    public function __construct(public int $seconds)
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('A Duration expiry must be a positive number of seconds.');
        }
    }

    public function expiresAt(int $grantedAtMs, int $periodEndMs): ?int
    {
        return $grantedAtMs + $this->seconds * 1000;
    }
}
