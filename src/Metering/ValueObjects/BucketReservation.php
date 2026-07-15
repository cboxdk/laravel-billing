<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

/**
 * A held bucket within a {@see ReservationSet}. Records how the reserved estimate
 * split against the meter's ISOLATED allowance:
 *
 *  - `sliceStart` — the pre-increment position the atomic disjoint-slice claim
 *                   returned; the exemption is computed from THIS claimed position,
 *                   never a prior read, so each included-allowance unit is consumed
 *                   exactly once under concurrency.
 *  - `exempt`     — units of the slice that fell within the isolated allowance (free).
 *  - `billable`   — overage units held against the leased paid budget.
 *
 * `policy` is retained so the weighted cost can be recomputed for the actual usage at
 * commit time. Immutable — commit/release consume it and produce durable events.
 */
readonly class BucketReservation
{
    public function __construct(
        public string $meter,
        public int $estimate,
        public int $sliceStart,
        public int $exempt,
        public int $billable,
        public MeterPolicy $policy,
    ) {}

    /** The weighted cost of the reserved (estimated) billable units. */
    public function estimatedCost(): float
    {
        return $this->policy->costFor($this->billable);
    }
}
