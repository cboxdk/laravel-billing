---
title: Requirements
description: Runtime requirements enforced by the package's composer constraints.
weight: 3
---

# Requirements

Cbox Billing installs only when your project satisfies the constraints declared in
the package's `composer.json`. These are the versions the dependency resolver
enforces — nothing more.

## PHP

- **PHP `^8.4`** — PHP 8.4 or newer. The library uses readonly value objects,
  enums, and first-class constructor promotion throughout.

## Laravel

The package depends on individual Illuminate components rather than the full
framework:

- **`illuminate/contracts` `^13.0`**
- **`illuminate/support` `^13.0`**
- **`illuminate/database` `^13.0`** — the durable ledger, event log, checkpoint,
  rollout journal, and currency-lock adapters.
- **`illuminate/cache` `^13.0`** — the app-local enforcement store.

In practice this is Laravel 13 — and **only** Laravel 13. This package does not
support Laravel 12, and no released version ever has. That is a deliberate choice
rather than a gap; see [ADR-0017](../adr/0017-single-laravel-major-support-window.md)
for the reasoning and when it will be revisited.

## Direct dependencies

- **`brick/money` `^0.14`** — money is integer minor units in immutable value
  objects, never floats. The `Cbox\Billing\Money\Money` VO wraps it.
- **`cboxdk/laravel-tax` `^0.1`** — the EU VAT engine (place of supply, reverse
  charge, VIES) behind the `TaxCalculator` contract. Drives quote tax lines.
- **`cboxdk/laravel-geo` `^0.4`** — geography primitives used by tax routing.
- **`cboxdk/license` `^0.1`** — Ed25519 license issue/verify, and the capability
  gate the commercial plugins read. First-party, MIT-licensed.

## What is *not* required

- **No gateway SDK.** The `PaymentGateway` contract is gateway-agnostic; Stripe
  and Mollie are separate opt-in adapter packages. The bundled
  `ManualPaymentGateway` has no external dependency.
- **No Redis and no ClickHouse.** The default stores are in-memory; the durable
  stores use your existing SQL connection. A ClickHouse event-log adapter binds
  the same contract for event-heavy scale but is optional. See
  [storage adapters](extension-points/storage-adapters.md).

## Related documentation

- [Installation](getting-started/installation.md)
- [Quick start](quickstart.md)
