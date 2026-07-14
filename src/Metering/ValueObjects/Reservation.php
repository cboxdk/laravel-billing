<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

use Cbox\Billing\Metering\Contracts\Enforcement;

/**
 * A held slice of local lease, returned by {@see Enforcement::reserve()}
 * and settled by commit/release. `amount` is the number of units held.
 */
readonly class Reservation
{
    public function __construct(
        public string $id,
        public string $org,
        public string $meter,
        public int $amount,
    ) {}
}
