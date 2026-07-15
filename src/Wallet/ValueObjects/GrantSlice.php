<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

/**
 * One vested slice of a plan grant: the integer `amount` that lands at the cadence
 * boundary `boundaryMs` (ms epoch), whose cadence period ends at `periodEndMs`. The
 * boundary is both the grant timestamp (idempotency keys on time, ADR-0002) and the
 * input to the grant's expiry policy (`EndOfPeriod` uses `periodEndMs`; `Duration`
 * uses `boundaryMs`).
 */
readonly class GrantSlice
{
    public function __construct(
        public int $boundaryMs,
        public int $amount,
        public int $periodEndMs,
    ) {}
}
