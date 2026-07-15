---
title: Reconcile usage
description: True the durable ledger up from the event log by posting a cumulative delta against each entity's checkpoint.
weight: 44
---

# Reconcile usage

Reconciliation posts the delta between the durable usage total and each entity's
checkpoint into the [ledger](../core-concepts/ledger.md), idempotently. It never
replays events (see [ADR-0003](../../adr/0003-convergent-reconciliation.md)).

## From the scheduler

The command reconciles every entity with usage since its checkpoint:

```php
// routes/console.php or a scheduler
Schedule::command('billing:reconcile')->everyFiveMinutes();
```

Size the cadence to your ingest lag — a delta missed this cycle is simply caught
next cycle.

## Programmatically

```php
use Cbox\Billing\Reconciliation\Contracts\Reconciler;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileTarget;

$report = $reconciler->reconcile([
    new ReconcileTarget($org, 'api.calls'),
    new ReconcileTarget($org, 'search.results'),
]);

foreach ($report->failures() as $failure) {
    Log::warning('Reconcile skipped an entity', ['entity' => $failure->target, 'error' => $failure->message]);
}
```

The reconciler:

- clamps the upper bound to `now − ingest_lag_seconds` so in-flight events are not
  counted early;
- attributes usage older than `window_days` to an `aged_out` account instead of the
  live bucket, never dropping it;
- reconciles each entity under a lock, **rethrowing** concurrency errors and
  **reporting-and-skipping** other per-entity errors so one bad entity cannot fail
  the batch.

## Durability

For production, set `reconciliation.checkpoint_store` to `database` and pair the
`DatabaseCheckpointStore` with a `DatabaseLedger` on the same connection so the
delta post and checkpoint advance share one transaction. See
[configuration](../configuration/reference.md).

## Related

- [Reconciliation](../core-concepts/reconciliation.md)
- [Ledger](../core-concepts/ledger.md)
