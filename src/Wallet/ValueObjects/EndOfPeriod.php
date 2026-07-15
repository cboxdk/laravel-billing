<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Wallet\Contracts\ExpiryPolicy;

/**
 * Use-it-or-lose-it: a lot expires at the end of the cadence period it was granted
 * into. A Monthly `EndOfPeriod` grant's lot dies at the next month boundary; a
 * daily-distributed slice dies at the next day boundary. No rollover — the period
 * resets (ADR-0013).
 */
readonly class EndOfPeriod implements ExpiryPolicy
{
    public function expiresAt(int $grantedAtMs, int $periodEndMs): ?int
    {
        return $periodEndMs;
    }
}
