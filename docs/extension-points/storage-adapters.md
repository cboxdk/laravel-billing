---
title: Storage adapters
description: Every durable store is memory by default and database on demand — plus the ClickHouse event-log seam for scale.
weight: 62
---

# Storage adapters

Each module that needs durability ships an in-memory default (zero config) and a
`database` adapter backed by your existing SQL connection. Select per module in
`config/billing.php`.

## Selectable stores

| Config key | `memory` | `database` | Migration |
| --- | --- | --- | --- |
| `metering.event_log` | `InMemoryEventLog` | `DatabaseEventLog` | `billing_usage_events` |
| `reconciliation.checkpoint_store` | `InMemoryCheckpointStore` | `DatabaseCheckpointStore` | `billing_usage_checkpoints` |
| `account.currency_lock_store` | `InMemoryBillingCurrencyLock` | `DatabaseBillingCurrencyLock` | `billing_account_currency_locks` |

The **ledger** (`DatabaseLedger`, tables `billing_ledger_lines` /
`billing_ledger_postings` + the immutability-hardening migration) and the
**entitlement rollout journal** (`DatabaseRolloutJournal`,
`billing_entitlement_rollouts`) also have durable adapters.

## Pair on the same connection

Some adapters must commit atomically together — bind them to the **same database
connection**:

- `DatabaseCheckpointStore` with `DatabaseLedger`, so a reconcile's delta post and
  checkpoint advance share one transaction.
- `DatabaseBillingCurrencyLock` with a durable `InvoiceNumberSequence`, so the
  first-finalize currency stamp and the invoice commit land together.

## The ClickHouse event-log seam

The [event log](../core-concepts/metering.md) is the metering source of truth and
the only store expected to reach event-heavy scale. A ClickHouse adapter binds the
same `EventLog` contract (append idempotent by event id, sum for invoice
computation) without touching any calling code. It is packaged separately and is
**optional** — `database` is plenty for most deployments. The idempotency model
([ADR-0002](../../adr/0002-ledger-idempotency-independent-of-partitioning.md)) is
deliberately independent of storage partitioning so this swap is safe.

## Related

- [Configuration reference](../configuration/reference.md)
- [Contracts & bindings](contracts-and-bindings.md)
