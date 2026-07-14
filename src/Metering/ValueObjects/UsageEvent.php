<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

/**
 * A committed unit of usage, appended to the durable buffer and later ingested
 * into billing's immutable event log. `id` is the stable dedup key (unique per
 * real event, stable across retries) so the same usage is counted exactly once.
 * `occurredAt` is a millisecond epoch timestamp, passed explicitly for
 * deterministic aggregation and testing.
 */
readonly class UsageEvent
{
    public function __construct(
        public string $id,
        public string $org,
        public string $meter,
        public string $service,
        public int $value,
        public int $occurredAt,
    ) {}
}
