# ADR-0013 — Unified entitlement: included allowances and credits compose in one burn-down, cadences per grant

**Status:** Accepted (2026-07-16)

## Context

The engine currently models two things separately:

- **Included metered allowances** — `MeterPolicy.allowance` (ADR-0005): a per-period included
  quantity for a meter, enforced on the hot path via the atomic disjoint-slice claim.
- **Credits** — Wallet pools/grants (ADR-0001): prepaid/promotional/granted balances, burned
  down by the credit consumer.

Products need these to **mix** inside one plan: a request should draw the per-period included
allowance **first**, then credits, then overage; and a single plan mixes **cadences** — a
daily free-tier reset, a monthly allotment, and a yearly bonus, all at once. "Everything must
mix." Two separate subsystems can't express a single deterministic burn-down across included +
credits, nor multiple reset cadences.

## Decision

**One entitlement model.** A plan entitlement is a grant `{target: meter | money, amount,
pool, cadence}` where `cadence ∈ {Once, Daily, Monthly, Yearly}`.

- The **included metered allowance is a recurring grant into the `included` pool** for that
  meter (denominated in the meter's units), reset each cadence period — not a separate scalar.
  A daily free tier is `cadence: Daily`; a monthly allowance `Monthly`; a yearly bonus
  `Yearly`.
- **One burn-down (ADR-0001).** A request for a meter draws in pool order across **all**
  sources: `included` (this period) → `promotional` → `purchased` / pay-as-you-go sink →
  overage. Included allowance, credits, and PAYG mix in one deterministic, reproducible order.
- **Cadences mix.** The cycle scheduler (ADR-0012) tracks a period **per (grant, cadence)**: a
  daily grant resets daily, a monthly grant monthly, a yearly grant yearly — each idempotent
  (`CycleGrants`, ADR-0002) on its own period, all within one subscription.
- **Per-grant expiry policy → rollover vs reset.** Each grant declares an expiry policy:
  `EndOfPeriod` (dies at the cadence boundary — no rollover, use-it-or-lose-it),
  `Duration(d)` (each lot lives `d` from when it was granted — unused **rolls over** and
  accumulates, each lot expiring on its own timer via lot-attributed expiry, ADR-0006), or
  `Never`. This composes with pool + cadence to express **multi-tier credits** in one plan —
  e.g. an `ai` pool granted Monthly with `EndOfPeriod` (monthly credits that don't roll over)
  **alongside** a `hosting` pool granted Monthly with `Duration(1 year)` (credits that roll
  over and expire a year after each grant). Rollover is expiry-beyond-the-period, not a
  separate mechanism.
- **The hot path stays fast.** The Metering lease (ADR-0005) leases a slice of the **combined
  remaining** spendable balance for a meter (the sum of that meter's spendable pools this
  period); per-request enforcement stays local/atomic, and the lease source derives the
  combined balance from the Wallet. The atomic disjoint-slice claim still governs
  exactly-once consumption within a leased slice.

## Consequences

- `MeterPolicy`'s per-period allowance becomes a recurring `included`-pool grant; the
  multi-dimensional bucketing, weighting, and isolation of ADR-0005 are unchanged — only the
  allowance's **home** moves into a pool grant so it can mix with credits. (ADR-0013 refines
  ADR-0005's allowance representation.)
- `GrantCadence` gains `Daily` / `Monthly` / `Yearly`; the renewal scheduler grants per
  (grant, cadence) period.
- The metering lease source computes its lease from the Wallet's combined spendable balance
  per meter; enforcement decisions and the plan preview show the composed picture (included +
  credits + overage).

## Alternatives considered

- **Keep two systems, compose only at the decision layer.** Rejected — duplicated period/reset
  logic and an ad-hoc burn-down order across two stores.
- **Model included allowance only in `MeterPolicy`.** Rejected — can't mix with credits, and
  can't express multiple reset cadences in one plan.
