<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

use Cbox\Billing\Metering\Contracts\Enforcement;

/**
 * One dimension of a multi-bucket request: `estimate` units to hold on `meter`. A
 * single {@see Enforcement::reserveBuckets()} call carries a set of these and
 * reserves them together (all-or-nothing), one independent bucket per meter.
 */
readonly class BucketRequest
{
    public function __construct(
        public string $meter,
        public int $estimate,
    ) {}
}
