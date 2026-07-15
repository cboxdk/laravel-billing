<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

/**
 * A single drawdown in a {@see ConsumptionPlan}: take `amount` from grant `grantId`,
 * which lives in pool `pool`. When `pool` is the PAYG sink the draw may push that
 * grant's remaining negative.
 */
readonly class Consumption
{
    public function __construct(
        public string $grantId,
        public int $amount,
        public string $pool,
    ) {}
}
