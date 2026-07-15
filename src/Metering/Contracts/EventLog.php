<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

use Cbox\Billing\Metering\ValueObjects\UsageEvent;

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
     * window [fromMs, toMs] — the aggregation an invoice is computed from.
     */
    public function sum(string $org, string $meter, int $fromMs, int $toMs): int;
}
