---
title: Core concepts
description: One page per module — the concepts, contracts, and invariants behind metering, wallets, the ledger, reconciliation, subscriptions, entitlements, accounts, catalog, invoicing, payments, and refunds.
weight: 20
---

# Core concepts

Each module is fronted by a contract in its `*/Contracts` namespace, bound in its
service provider, and ships an in-memory default plus (where it needs durability)
a `database` adapter and a dogfooded testing seam. These pages explain the
concept and the invariants; the [cookbook](../cookbook/_index.md) shows the code.

## Pages

| Page | Module(s) | Key ADRs |
| --- | --- | --- |
| [Metering & enforcement](metering.md) | Metering | [0004](../../adr/0004-enforcement-failure-policy.md), [0005](../../adr/0005-multi-dimensional-metering.md), [0008](../../adr/0008-derived-hot-path-balance.md) |
| [Wallets & credits](wallets.md) | Wallet | [0001](../../adr/0001-credit-pools-behaviour-matrix.md), [0006](../../adr/0006-credit-lots-expiry-forfeiture.md) |
| [Ledger](ledger.md) | Ledger, Money | [0002](../../adr/0002-ledger-idempotency-independent-of-partitioning.md) |
| [Reconciliation](reconciliation.md) | Reconciliation | [0003](../../adr/0003-convergent-reconciliation.md) |
| [Subscriptions & proration](subscriptions.md) | Subscription | [0007](../../adr/0007-preview-equals-charge.md), [0006](../../adr/0006-credit-lots-expiry-forfeiture.md) |
| [Entitlements](entitlements.md) | Entitlement | — |
| [Accounts](accounts.md) | Account | — |
| [Catalog & pricing](catalog-and-pricing.md) | Catalog, Pricing, Seller | — |
| [Quotes & invoicing](quotes-and-invoicing.md) | Quote, Invoice | [0007](../../adr/0007-preview-equals-charge.md) |
| [Payments & dunning](payments-and-dunning.md) | Payment | — |
| [Refunds & chargebacks](refunds-and-chargebacks.md) | Refund | — |
| [Reporting & SaaS metrics](reporting.md) | Reporting | — |

## The load-bearing split

Everything on these pages is an expression of the same decision: **enforcement,
metering truth, and money are three separate layers** (see
[Architecture](../getting-started/architecture.md)). Metering enforces and
records; the ledger is the money authority; reconciliation trues one up from the
other. No page's fast counter is ever the source of truth for money.
