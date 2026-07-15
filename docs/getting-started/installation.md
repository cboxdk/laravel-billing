---
title: Installation
description: Install Cbox Billing, publish its config, choose durable stores, and run the migrations.
weight: 11
---

# Installation

## Requirements

- PHP 8.4+
- Laravel 13
- `brick/money`, `cboxdk/laravel-tax`, `cboxdk/laravel-geo` (pulled in as
  dependencies)

See [Requirements](../requirements.md) for the exact constraints.

## Install via Composer

```bash
composer require cboxdk/laravel-billing
```

The package auto-discovers `Cbox\Billing\BillingServiceProvider`, which registers
every module's service provider. No manual registration is needed.

## Publish configuration

```bash
php artisan vendor:publish --tag="billing-config"
```

This writes `config/billing.php`. All stores default to `memory`, so publishing is
optional until you want durable storage or want to tune the dunning, lease, or
reconciliation knobs. See the [configuration reference](../configuration/reference.md).

## Choose durable stores

Each module has an in-memory default (zero config, good for tests and small
single-node setups) and a `database` adapter backed by your existing SQL
connection. Switch the ones you need in `config/billing.php`:

| Config key | `memory` (default) | `database` |
| --- | --- | --- |
| `metering.event_log` | `InMemoryEventLog` | `DatabaseEventLog` |
| `reconciliation.checkpoint_store` | `InMemoryCheckpointStore` | `DatabaseCheckpointStore` |
| `account.currency_lock_store` | `InMemoryBillingCurrencyLock` | `DatabaseBillingCurrencyLock` |

The ledger, entitlement rollout journal, and usage checkpoints also ship durable
adapters. See [storage adapters](../extension-points/storage-adapters.md).

## Run migrations

The durable adapters ship migrations for:

- `billing_ledger_lines`, `billing_ledger_postings` (+ an immutability-hardening
  migration)
- `billing_usage_events`
- `billing_usage_checkpoints`
- `billing_entitlement_rollouts`
- `billing_account_currency_locks`

```bash
php artisan migrate
```

Pair adapters that must commit atomically on the **same connection** — for
example a durable checkpoint store with a durable ledger so a delta post and its
checkpoint advance share one transaction (see
[Reconciliation](../core-concepts/reconciliation.md)), and the durable currency
lock with a durable invoice number sequence so the first-finalize stamp and the
invoice commit land together.

## Verify installation

Bind an `AllowanceLeaseSource` and run a hot-path check (the
[quick start](../quickstart.md) walks through it), or run the reconcile command:

```bash
php artisan billing:reconcile
```

## Related documentation

- [Quick start](../quickstart.md)
- [Configuration reference](../configuration/reference.md)
- [Core concepts](../core-concepts/_index.md)
