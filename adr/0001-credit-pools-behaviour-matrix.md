# ADR-0001 — Credit accounts are pools with a per-pool behaviour matrix

**Status:** Accepted (2026-07-15)

## Context

Usage-metered billing needs several *kinds* of credit that behave differently, in the
same account, at the same time:

- **Recurring plan allotment** — granted each cycle, spendable, typically **forfeited**
  when the subscription ends (not rolled over, not refunded).
- **Purchased / pay-as-you-go** — top-ups and accrued overage. Never forfeits, and is the
  one balance that may go **negative** (accumulated overage settled at the cycle boundary).
- **Regulated credit** — e.g. licensing-bound units that must be **tracked and reported**
  but **never spent** against general usage, and that a jurisdiction may require to carry an
  **expiry** (a grant without one is invalid).

A single fungible `balance: int`, or a flat list of grants distinguished only by burn-down
priority and an optional expiry, cannot express *may-go-negative*, *forfeit-on-cancel*,
*must-expire*, or *tracked-but-unspendable*. Our current `Wallet\CreditGrant` +
`CreditType` is exactly that flat model.

## Decision

Introduce a first-class **`Pool`** with an explicit **behaviour matrix**:

- `spendable` — may fund general usage.
- `mayGoNegative` — accrues overage as debt (the PAYG sink).
- `forfeitsOnCancel` — zeroed when the org leaves the granting subscription.
- `requiresExpiry` — a grant into this pool **must** carry an `expiresAt` (grant refused otherwise).
- `reportable` — counted for regulatory reporting even when not spendable.

A plan grants credits as **`(pool, kind, cadence)` rows**, not scalars:

- `kind ∈ {Base, PerSeat}` — flat per-cycle allotment vs per-additional-seat.
- `cadence ∈ {Once, Recurring}` — one-off vs granted every cycle.

Balances are per **`(org, pool)`**. Burn-down consumes an **ordered pool list**; the last pool
in the order absorbs any remainder, and if that pool is `mayGoNegative` it becomes the PAYG
sink. Non-spendable pools are never in the consumption order.

## Consequences

- New model: `Pool` (+ behaviour flags) and `plan credit grant` rows keyed by `(pool, kind, cadence)`.
- `Wallet` API becomes pool-aware (`balance(org, pool)`, `consume(org, order, amount)`, `grant(...)` validating `requiresExpiry`).
- Forfeiture (ADR-0006), regulatory tracking, and PAYG-as-negative all become expressible.
- Migration from the flat `CreditType` model: map existing types onto default pools.

## Alternatives considered

- **Flat grants + priority/expiry (status quo).** Rejected — cannot express the matrix.
- **One balance allowed to go negative.** Rejected — conflates independent pools; a negative
  PAYG balance would offset a forfeitable allotment.
- **Behaviour on the grant, not the pool.** Rejected — behaviour is a property of the *account
  the credit lives in*; putting it on each grant duplicates and lets grants disagree within a pool.
