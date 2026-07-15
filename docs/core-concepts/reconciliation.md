---
title: Reconciliation
description: Convergent reconciliation — a cumulative delta against a per-entity checkpoint, tolerant of a laggy, lossy, reordering async pipeline, with ingest-lag and aged-out guards.
weight: 24
---

# Reconciliation

The hot path enforces against a fast, app-local counter; the durable ledger is the
authority. Usage flows from one to the other through an **asynchronous pipeline
that is laggy, lossy, and can reorder or duplicate** events. Reconciliation closes
that gap — without ever requiring exactly-once, in-order delivery.

## Convergent by construction (ADR-0003)

Reconciling by *replaying individual events* would need exactly-once delivery and
per-row idempotency — guarantees the pipeline does not offer. Instead the
reconciler computes a **delta against a checkpoint**:

For each entity:

1. Read the **cumulative** usage total from the durable source since the entity's
   checkpoint.
2. Subtract the **prior checkpoint total** → the delta to post to the ledger.
3. Advance the checkpoint.

Late, duplicated, or out-of-order events are simply caught by the next cycle — the
arithmetic self-corrects. See
[ADR-0003](../../adr/0003-convergent-reconciliation.md).

```php
interface Reconciler
{
    public function reconcile(iterable $targets, ?int $now = null): ReconcileReport;
}
```

`DefaultReconciler` posts `sum(events) − checkpoint.total` into the
[`Ledger`](ledger.md) idempotently, keyed by the natural `PostingKey` (ADR-0002),
so a re-run of the same cycle is a no-op.

## Guards

- **Ingest-lag clamp.** The upper bound is `now − ingestLag`
  (`reconciliation.ingest_lag_seconds`), so in-flight events that have not fully
  landed are not counted early — they are picked up once the clamp advances past
  them.
- **Aged-out bucketing.** Usage older than the reconcile window
  (`reconciliation.window_days`) is attributed to an `aged_out` account, never
  silently dropped. A far-late straggler still reaches the ledger, kept separate
  for audit.
- **Per-entity checkpoint lock.** Each entity reconciles under a lock.
  **Concurrency errors rethrow** (a swallowed deadlock would leave the outer
  transaction half-rolled-back); other per-entity errors are reported and
  skipped so one bad entity cannot fail the batch. `NonMonotonicCheckpoint`
  guards a checkpoint moving backwards.

## Checkpoint store

```php
interface CheckpointStore
{
    public function load(string $org, string $meter): Checkpoint;
    public function transactionally(string $org, string $meter, callable $mutator): Checkpoint;
}
```

`InMemoryCheckpointStore` is the default; `DatabaseCheckpointStore` (migration
`billing_usage_checkpoints`) is durable — pair it with a `DatabaseLedger` on the
same connection so the delta post and the checkpoint advance share one
transaction.

The delta is carried into the ledger in the allowance denomination
(`reconciliation.currency`), which is the unit the derived hot-path balance reads
(ADR-0008), not a priced amount. Reporting balances may therefore lag the ledger
by up to one drift window — acceptable and documented, and never used for a hard
money decision on the hot path.

## Running it

`php artisan billing:reconcile` runs the reconciler. Schedule it on the cadence
your ingest lag warrants.

## Testing

`Cbox\Billing\Reconciliation\Testing\InteractsWithReconciliation` and
`FakeCheckpointStore` drive the delta arithmetic and the guards.

## Related

- [Ledger](ledger.md)
- [Metering & enforcement](metering.md)
- [Cookbook: reconcile usage](../cookbook/reconcile-usage.md)
