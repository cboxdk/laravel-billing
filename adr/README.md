# Architecture Decision Records — Cbox Billing

Decisions that shape the billing engine's data model and contracts. Each ADR is
immutable once **Accepted**; supersede rather than edit.

These decisions target a demanding bar: the engine must be able to bill a
**high-volume, usage-metered API product** — independent weighted meters, credit
pools with regulatory and pay-as-you-go semantics, per-cycle and per-seat grants,
proration that reconciles to the payment gateway to the cent — as a first-class
customer, not a special case.

> Decisions are stated in our own terms. Do not attribute them to, or name, any
> specific third-party product studied during analysis.

| ADR | Decision |
|---|---|
| [0001](0001-credit-pools-behaviour-matrix.md) | Credit accounts are **pools** with a per-pool behaviour matrix |
| [0002](0002-ledger-idempotency-independent-of-partitioning.md) | Ledger **idempotency** independent of storage partitioning |
| [0003](0003-convergent-reconciliation.md) | **Convergent reconciliation** — cumulative delta vs a checkpoint |
| [0004](0004-enforcement-failure-policy.md) | Enforcement **fails open on infrastructure, closed on semantics** |
| [0005](0005-multi-dimensional-metering.md) | **Multi-dimensional metering** with isolated allowances |
| [0006](0006-credit-lots-expiry-forfeiture.md) | Credit **lot accounting**, expiry, and forfeiture |
| [0007](0007-preview-equals-charge.md) | **Preview equals charge**; per-line rounding to the gateway |
| [0008](0008-derived-hot-path-balance.md) | Hot-path balance is **derived**, never a loose cached number |
| [0009](0009-product-integration-hosted-and-embedded.md) | Product integration: **hosted sessions** and **embedded intents** (platform-level: app + adapters + SDK) |
| [0010](0010-plan-families-and-transition-policy.md) | **Plan families** + transition policy (deny-by-default; legacy plans are one-way) |
| [0011](0011-credit-consequences-of-transitions.md) | **Credit consequences** of plan change / cancel (forfeit-and-regrant default; preview shows the delta) |
| [0012](0012-billing-cycle-anchor-and-proration-mechanics.md) | **Billing cycle anchor** + month-end clamp, mid-cycle credit proration, add-on cycles |

**Format:** Status · Context · Decision · Consequences · Alternatives considered.
