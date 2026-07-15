---
title: Getting started
description: Install the package, understand the three-layer architecture, and find your way around the modules.
weight: 10
---

# Getting started

Cbox Billing is a consolidated engine of billing modules, each fronted by a
contract you bind in its service provider and each shipping an in-memory default
plus a durable adapter. Start here to install it and to get the mental model
before diving into individual modules.

## In this section

| Page | What |
| --- | --- |
| [Installation](installation.md) | Composer install, config publish, migrations, and durable-store selection. |
| [Architecture & foundation contracts](architecture.md) | The three-layer model (enforcement / metering / money), the data model, ledger invariants, and the cbox-id seam. |

## The module map

| Module | Concept page | Responsibility |
| --- | --- | --- |
| Metering | [Metering & enforcement](../core-concepts/metering.md) | Real-time hard limits, multi-dimensional buckets, the immutable event log. |
| Wallet | [Wallets & credits](../core-concepts/wallets.md) | Credit pools, the behaviour matrix, lot expiry, forfeiture. |
| Ledger | [Ledger](../core-concepts/ledger.md) | Double-entry, append-only, derived balances, two-phase transfers. |
| Reconciliation | [Reconciliation](../core-concepts/reconciliation.md) | Convergent delta-vs-checkpoint truing-up. |
| Subscription | [Subscriptions & proration](../core-concepts/subscriptions.md) | Lifecycle, preview-equals-charge proration, forfeiture transitions. |
| Entitlement | [Entitlements](../core-concepts/entitlements.md) | Coarse tier projection, outage audit, bulk rollout. |
| Account | [Accounts](../core-concepts/accounts.md) | Currency lock, account standing. |
| Catalog / Pricing / Seller | [Catalog & pricing](../core-concepts/catalog-and-pricing.md) | Versioned products/prices, coupons, seller-of-record routing. |
| Quote / Invoice | [Quotes & invoicing](../core-concepts/quotes-and-invoicing.md) | Tax-composed quotes, per-seller invoice numbering, credit notes. |
| Payment | [Payments & dunning](../core-concepts/payments-and-dunning.md) | Gateway-agnostic charging, dunning, the webhook ingest seam. |
| Refund | [Refunds & chargebacks](../core-concepts/refunds-and-chargebacks.md) | First-class refunds and chargebacks as ledger reversals. |

## Related documentation

- [Quick start](../quickstart.md)
- [Requirements](../requirements.md)
