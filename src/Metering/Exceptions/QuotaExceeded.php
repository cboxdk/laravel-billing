<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Exceptions;

use RuntimeException;

/**
 * The hard limit: the organization's remaining allowance for a meter is exhausted
 * and cannot satisfy a reservation. Thrown on the hot path so the caller can deny
 * or degrade the operation.
 */
class QuotaExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $org,
        public readonly string $meter,
        public readonly int $requested,
    ) {
        parent::__construct("Quota exceeded for meter [{$meter}] on organization [{$org}]: {$requested} unit(s) requested, none available.");
    }
}
