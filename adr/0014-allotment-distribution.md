# ADR-0014 — Allotment distribution: drip a billing-period total across a finer cadence

**Status:** Accepted (2026-07-16)

## Context

A subscription's billing interval (e.g. **yearly**) may carry an allotment that should not be
handed over as one lump at the start of the period, but **distributed** across a finer cadence
— daily, weekly, monthly, quarterly, or half-yearly. A yearly plan with 1,200,000 credits
might drip 100,000 per month, or ~3,288/day. This must work for **credits and included
allowances** alike, and the slices must sum **exactly** to the stated total (no rounding
drift). ADR-0013 gives per-grant cadences and expiry; it does not yet express "take this
period total and split it."

## Decision

- **Expanded cadence set:** `{Once, Daily, Weekly, Monthly, Quarterly, HalfYearly, Yearly}`.
- A grant's per-cadence amount is one of:
  - **`Fixed(amount, cadence)`** — grant `amount` each cadence period (ADR-0013 as-is);
  - **`Distributed(total, cadence)`** — split `total` **evenly across the cadence slices in
    the billing period** (ADR-0012). E.g. a yearly billing period, `Distributed(1_200_000,
    Monthly)` → 12 × 100,000; `Distributed(1_200_000, Daily)` → one slice per actual day of
    that year, the slices summing to **exactly** 1,200,000.
- **Remainder-safe integer allocation.** Slices are whole minor units and sum to the total to
  the unit — the remainder is spread (largest-remainder / `Money::allocate`-style), never
  dropped or duplicated. Leap years and 30/31-day months use the **actual** slice count.
- **Aligned to the anchor/period (ADR-0012).** Slices are scheduled on the cadence within
  `[period_start, period_end)` with the same month-end clamp. A subscription starting
  mid-billing-period begins at the **next slice boundary** by default; a plan MAY prorate the
  first partial slice.
- **Composes with expiry (ADR-0013).** Each distributed slice is a `Recurring` grant of the
  slice amount carrying the grant's expiry policy — so a daily-distributed included allowance
  can be `EndOfPeriod` (each day's slice expires that day — no rollover) or `Duration(d)`
  (slices accumulate/roll over). Applies uniformly to credits **and** included allowances
  (unified pool grants, ADR-0013).
- **Idempotent per slice** (`CycleGrants`, ADR-0002): a slice for a given boundary is granted
  at most once.

## Consequences

- The grant model gains a `Fixed | Distributed` amount mode; the cadence enum gains
  `Weekly` / `Quarterly` / `HalfYearly`.
- The cycle scheduler (ADR-0012, #58) computes the slice schedule + remainder-safe amounts for
  a `Distributed` grant and fires each slice idempotently on its boundary.
- Preview/usage surfaces reflect the dripped balance (what has vested this period vs the
  period total).

## Alternatives considered

- **Author the per-slice amount by hand (`Fixed` only).** Kept as an option, but distribution
  lets a plan state the period total once and stay correct across variable slice counts
  (leap years, month lengths) with no drift.
- **Float division of the total.** Rejected — money/credits are integer minor units; even
  allocation with a spread remainder is the only drift-free split.
