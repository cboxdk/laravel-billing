---
title: Introduction
description: The gateway-agnostic billing engine for Laravel — catalog, subscriptions, real-time usage metering with hard limits, a double-entry ledger, wallets, invoicing, and pricing.
weight: 1
---

# Cbox Billing

`cboxdk/laravel-billing` is the billing engine for Laravel: a gateway-agnostic
library of billing primitives you compose into a billing product. It is the
framework peer to [`cboxdk/laravel-id`](https://github.com/cboxdk/laravel-id) —
UI-free and domain-free. Every capability sits behind a contract you bind, mock,
or replace.

This is the **package**, not the product. The deployable, self-hostable billing
app built on it — with an admin console and customer portal — is the separate
`cboxdk/cbox-billing` application, exactly as `cbox-id` is the app built on
`laravel-id`. Reach for the app if you don't want to build the UI and hosting
layer yourself; reach for this package to embed billing in your own Laravel app.

## The mental model: three separate concerns

Real-time enforcement, metering truth, and money are **three separate layers**.
The invoice is **computed from the immutable event log**, never read from a
counter. Keeping these apart is the load-bearing decision the whole library is
built around.

| Layer | Question it answers | Store | Authority |
| --- | --- | --- | --- |
| **1 · Enforcement** | *May this request proceed?* (sub-ms) | App-local counter (Laravel cache, atomic increment) | none — eventually consistent, small bounded drift |
| **2 · Metering truth** | *What actually happened?* | Immutable append-only usage event log | metering source of truth |
| **3 · Money** | *What is owed / paid / owned?* | Double-entry ledger, balances derived from postings | money source of truth |

Hard limits are enforced **locally per node** against a **leased slice** of the
org's allowance — no shared or co-located Redis required. A small, bounded,
backfillable drift is accepted by design, and [reconciliation](core-concepts/reconciliation.md)
trues the ledger up from the event log. The full rationale is in
[Architecture & foundation contracts](getting-started/architecture.md).

## Sections

- **[Getting started](getting-started/_index.md)** — install, the three-layer
  architecture, and the module map.
- **[Core concepts](core-concepts/_index.md)** — one page per module: metering,
  wallets, ledger, reconciliation, subscriptions, entitlements, accounts,
  catalog, quotes and invoicing, payments, refunds.
- **[Cookbook](cookbook/_index.md)** — task-first recipes: enforce a hard limit,
  grant and burn credits, reconcile usage, preview a plan change, ingest a
  payment webhook, roll out a plan entitlement.
- **[Extension points](extension-points/_index.md)** — the contracts you bind,
  storage adapters, gateway adapters, and the dogfooded testing seams.
- **[Configuration](configuration/_index.md)** — every `config/billing.php` key.
- **[Security](security/_index.md)** — an honest threat model and the app-layer
  vs library boundary.

## Design decisions (ADRs)

The decisions that hardened the surface are recorded as ADRs, linked inline from
the relevant concept pages:

- [ADR-0001 — Credit pools with a behaviour matrix](../adr/0001-credit-pools-behaviour-matrix.md)
- [ADR-0002 — Ledger idempotency independent of partitioning](../adr/0002-ledger-idempotency-independent-of-partitioning.md)
- [ADR-0003 — Convergent reconciliation](../adr/0003-convergent-reconciliation.md)
- [ADR-0004 — Enforcement fails open on infra, closed on semantics](../adr/0004-enforcement-failure-policy.md)
- [ADR-0005 — Multi-dimensional metering with isolated allowances](../adr/0005-multi-dimensional-metering.md)
- [ADR-0006 — Credit lots, expiry, and forfeiture](../adr/0006-credit-lots-expiry-forfeiture.md)
- [ADR-0007 — Preview equals charge](../adr/0007-preview-equals-charge.md)
- [ADR-0008 — Derived hot-path balance](../adr/0008-derived-hot-path-balance.md)

## Related documentation

- [Quick start](quickstart.md)
- [Requirements](requirements.md)
