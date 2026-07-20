<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;

/**
 * The immutable usage event log — the metering source of truth. Invoices are
 * COMPUTED from it (summed with the pinned price), never read from a counter.
 *
 * Storage is deliberately abstract: a relational store (MySQL/Postgres) is plenty
 * for small deployments, while an event-heavy, high-cardinality workload swaps in a
 * columnar/OLAP store (ClickHouse) behind the same contract — no calling code
 * changes. Appends are idempotent (dedup by the event's stable id).
 */
interface EventLog
{
    /**
     * Append events, ignoring any whose id is already stored. Returns the number
     * newly appended.
     *
     * @param  list<UsageEvent>  $events
     */
    public function append(array $events): int;

    /**
     * Total usage `value` for an organization + meter within the millisecond-epoch
     * HALF-OPEN window [fromMs, toMs) — `fromMs` inclusive, `toMs` exclusive — the
     * aggregation an invoice is computed from. The exclusive upper bound matches
     * {@see BillingPeriod}'s `[start, end)`, so
     * an event at a period boundary ms is counted in exactly one adjacent period.
     *
     * Retained as the ubiquitous shorthand for {@see aggregate()} with
     * {@see Aggregation::Sum}; implementers route it through the same path.
     */
    public function sum(string $org, string $meter, int $fromMs, int $toMs): int;

    /**
     * Collapse an organization + meter's events in the millisecond-epoch HALF-OPEN
     * window [fromMs, toMs) — `fromMs` inclusive, `toMs` exclusive — into ONE billable
     * quantity under the given billable-metric
     * {@see Aggregation} — `Count`, `Sum`, `Max`, `UniqueCount`, `Latest`, or
     * `WeightedSum`. An empty window yields 0 (`Max`/`Latest` too). This is the meter
     * side of the billable-quantity → tiered-price pipeline.
     */
    public function aggregate(string $org, string $meter, int $fromMs, int $toMs, Aggregation $aggregation): int;
}
