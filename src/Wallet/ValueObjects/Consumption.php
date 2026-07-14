<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

/** A single drawdown in a {@see ConsumptionPlan}: take `amount` from grant `grantId`. */
readonly class Consumption
{
    public function __construct(
        public string $grantId,
        public int $amount,
    ) {}
}
