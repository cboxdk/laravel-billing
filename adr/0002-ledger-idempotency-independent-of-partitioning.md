# ADR-0002 — Ledger idempotency independent of storage partitioning

**Status:** Accepted (2026-07-15)

## Context

The credit/usage ledger is append-only and, at volume, will be **partitioned by time**. On a
partitioned table, every UNIQUE index must include the partition key — so a
`UNIQUE(event_id)` (the obvious way to make posting idempotent) becomes **impossible** once
the ledger is partitioned. Building idempotency on a database constraint we will later be
forced to drop is a trap: it works in every single-writer test and fails silently under the
production schema.

Idempotency here has two shapes: **posting** a usage/credit transaction exactly once despite
retries and async reprocessing, and **granting** recurring cycle credits exactly once per
cycle despite an overlapping cron and webhook.

## Decision

Idempotency is an **application-level** property, never a partitioned-table unique constraint.

- **Event ingestion** dedups on the event's stable id. The columnar event store uses
  `ReplacingMergeTree(event_id)` + query-time dedup; the relational store dedups via an
  existence check under a short-lived lock, or a **separate, unpartitioned** dedupe table.
- **Ledger posting** is idempotent on a natural key (`org, source, reference`) — a re-post is
  a no-op — enforced in code, not by a unique index on the partitioned ledger.
- **Recurring cycle grants** are idempotent on **time, not a marker**: a grant into the pool
  with `created_at ≥ period_start` means "already granted this cycle". A lagging cycle mirror
  can stamp a stale period id, so the period *timestamp* is the dedup key; any period-id
  metadata is audit-only.

## Consequences

- The `Ledger` / `EventLog` contracts document the idempotency key for each operation.
- `DatabaseLedger` uses upsert / existence-check, and never assumes a unique index it will
  lose to partitioning.
- Partitioning can be introduced later with **no change** to the idempotency story.

## Alternatives considered

- **`UNIQUE(event_id)` on the ledger.** Rejected — illegal on a partitioned table; a latent
  correctness cliff.
- **No idempotency, rely on exactly-once delivery.** Rejected — the async pipeline (ADR-0003)
  is lossy and reordering; double-posting would double-bill.
- **Marker column for cycle grants.** Rejected — a lagging period mirror makes the marker
  unreliable; timestamp-vs-period-start is robust.
