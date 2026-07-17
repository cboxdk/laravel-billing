<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

/**
 * A committed unit of usage, appended to the durable buffer and later ingested
 * into billing's immutable event log. `id` is the stable dedup key (unique per
 * real event, stable across retries) so the same usage is counted exactly once.
 * `occurredAt` is a millisecond epoch timestamp, passed explicitly for
 * deterministic aggregation and testing.
 *
 * `value` carries the numeric measurement the `Sum`/`Max`/`Latest`/`WeightedSum`
 * aggregations read (pass 1 for a plain per-event count). Two optional fields feed
 * the richer billable-metric aggregations, both trailing and defaulted so existing
 * ingest is unaffected:
 *
 *  - `uniqueKey` — the dimension counted DISTINCTLY by `UniqueCount` (e.g. a user id
 *                  for unique-active-users). Null means the event carries no such key.
 *  - `weight`    — the per-event multiplier `WeightedSum` applies (`value × weight`);
 *                  defaults to 1 so a weighted sum with no weights equals a plain sum.
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
        public ?string $uniqueKey = null,
        public int $weight = 1,
    ) {}
}
