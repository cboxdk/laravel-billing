# ADR-0003 — Convergent reconciliation (cumulative delta vs a checkpoint)

**Status:** Accepted (2026-07-15)

## Context

The hot path enforces against a fast, app-local counter; the durable ledger is the authority.
Usage flows from the hot path to the durable store through an **asynchronous pipeline that is
laggy, lossy, and can reorder or duplicate** events. Reconciliation closes the gap between the
fast counter and the ledger.

Reconciling by **replaying individual events** requires exactly-once, in-order delivery and
per-row idempotency — guarantees we do not have and do not want to depend on.

## Decision

Reconciliation is **convergent by construction**: it computes a delta against a checkpoint,
not a replay.

For each entity:

1. Read the **cumulative** usage total from the durable source since the entity's checkpoint.
2. Subtract the **prior checkpoint total** → the **delta** to post to the ledger.
3. Advance the checkpoint.

Guards:

- **Ingest-lag clamp** — the upper bound is `now − ingestLag`, so in-flight events aren't
  counted before they've fully landed.
- **Aged-out bucketing** — records older than the reconcile window are attributed to an
  `aged_out` bucket, never silently dropped.
- **Per-entity checkpoint lock** for the reconcile; **concurrency errors rethrow** (a swallowed
  deadlock leaves the outer transaction half-rolled-back); other per-entity errors are reported
  and skipped so one bad entity can't fail the batch.

Late, duplicated, or out-of-order events are simply caught by the next cycle — the arithmetic
self-corrects.

## Consequences

- The reconciler tolerates a lossy/laggy async pipeline with no exactly-once requirement.
- Reporting balances may lag the ledger by up to one drift window — acceptable and documented;
  never used for hard money decisions on the hot path.
- Requires a durable **cumulative** usage total per entity and a **checkpoint** store.

## Alternatives considered

- **Per-event replay.** Rejected — needs exactly-once delivery + per-row idempotency.
- **Trust the hot counter as authority.** Rejected — it is optimistic and lossy; the ledger is
  the authority, the counter is a fast approximation.
