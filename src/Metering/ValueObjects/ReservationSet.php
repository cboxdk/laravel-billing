<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

use Cbox\Billing\Metering\Contracts\Enforcement;

/**
 * A set of buckets reserved together by {@see Enforcement::reserveBuckets()} for one
 * organization. Each bucket is evaluated INDEPENDENTLY and is never collapsed into a
 * single number; the only aggregate is the total cost, which is the SUM of the
 * per-bucket weighted usage (ADR-0005). Settled as a whole by commit/release.
 */
readonly class ReservationSet
{
    /**
     * @param  list<BucketReservation>  $buckets
     */
    public function __construct(
        public string $id,
        public string $org,
        public array $buckets,
    ) {}

    /** The bucket held for `$meter`, or `null` if this set holds none. */
    public function bucket(string $meter): ?BucketReservation
    {
        foreach ($this->buckets as $bucket) {
            if ($bucket->meter === $meter) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * Total cost = Σ per-bucket weighted usage for the reserved estimates. Buckets
     * are summed, never collapsed before evaluation.
     */
    public function estimatedCost(): float
    {
        $total = 0.0;

        foreach ($this->buckets as $bucket) {
            $total += $bucket->estimatedCost();
        }

        return $total;
    }
}
