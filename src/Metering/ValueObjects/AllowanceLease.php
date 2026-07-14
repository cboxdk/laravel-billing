<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

/**
 * The result of leasing allowance from the central budget: `granted` units the
 * node may now spend locally (0 when the org's allowance is exhausted).
 */
readonly class AllowanceLease
{
    public function __construct(
        public string $org,
        public string $meter,
        public int $granted,
    ) {}
}
