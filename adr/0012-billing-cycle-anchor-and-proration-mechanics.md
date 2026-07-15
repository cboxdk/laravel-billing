# ADR-0012 — Billing cycle anchor, period math, and mid-cycle proration (money + credits) + add-on cycles

**Status:** Accepted (2026-07-16)

## Context

ADR-0007 prorates **money** within a given period; ADR-0011 governs **credit** forfeiture
and regranting on a transition. Neither defines how the **period itself** is computed, nor
how a per-cycle **credit allotment** prorates on a mid-cycle change, nor how **add-ons**
align to cycles. Real subscriptions need:

- a **billing anchor** (calendar-aligned to the 1st, or aligned to the signup day), with the
  **month-end problem** handled (an anchor on the 31st must still bill in February);
- a per-cycle credit allotment (e.g. 50,000/month) that behaves correctly when a customer
  **upgrades mid-cycle** to a larger allotment (100,000);
- **add-ons** that either follow the base subscription's cycle or run on their own.

## Decision

### Billing anchor + period math
- A subscription carries a `billing_anchor_day` (1–31) and an interval (`month` / `year`).
  Default anchor = the **signup day**; a plan/config MAY pin it to the **1st** (calendar
  aligned). The anchor is stored as an explicit day + zone (no implicit local time).
- **Month-end clamp:** when the anchor day exceeds the days in the target month, the boundary
  clamps to the **last day of that month** — a 31 anchor bills Jan 31, **Feb 28/29**, Mar 31,
  Apr 30 — and the intended anchor day is preserved for months that have it (never drifts
  earlier permanently). 29/30 clamp the same way.
- A cycle is the half-open interval `[anchor_this, anchor_next)`; all proration (ADR-0007)
  derives its `remaining/total` from this interval, which already clamps a pre-start instant
  and guards a zero-length period.

### Mid-cycle credit proration
- On a **mid-cycle upgrade**, money prorates per ADR-0007 (charge the delta for the remaining
  days). For the **credit allotment**, the default is **reset to the incoming plan's full
  cycle allotment** for the remainder of the cycle (forfeit-and-regrant, ADR-0011) — the
  customer immediately gets the new limit, and the money proration already charged the
  prorated capacity. A plan/edge MAY opt into **prorated credit granting** — grant
  `remaining_days / total_days × new_allotment` (minus what the old allotment already
  provided) — for plans where credits map directly to money and a full reset would over-grant.
- On a **deferred downgrade** (ADR-0007, effective at period end) the credit allotment changes
  **at period end**, not mid-cycle.
- Purchased / pay-as-you-go and promotional/regulated pools are unaffected by allotment
  proration (ADR-0011).

### Add-on cycles
- An add-on is billed either **aligned** to the base subscription's cycle (default — same
  anchor; a mid-cycle add prorates to the base period and its credits follow the base
  allotment rules above) or on its **own independent cycle** (its own anchor + period math).
  The mode is a property of the add-on. Aligned is the common case; independent supports
  add-ons with a different cadence than the base.

## Consequences

- The engine gains a **cycle/anchor** value object + scheduler (anchor day, interval,
  month-end clamp) that produces the `BillingPeriod` proration already consumes; a
  scheduled command advances cycles and fires renewals/allotment grants on the anchor.
- The plan-change preview (ADR-0007/0011) reports the prorated **money** and the **credit
  delta** using the anchored period; the credit-granting mode (full reset vs prorated) is
  explicit.
- Add-ons carry an alignment mode; aligned add-ons share the base period, independent ones
  schedule their own.

## Alternatives considered

- **Anchor drifts to the earliest short-month day permanently.** Rejected — a 31 anchor would
  become a 28 anchor forever after one February; preserve the intended day.
- **Full-reset credits only (no prorated option).** Kept as the default, but prorated granting
  is offered for money-equivalent credits to avoid over-granting on frequent upgrades.
- **Add-ons always follow the base cycle.** Rejected — some add-ons genuinely need their own
  cadence; make it a per-add-on choice with base-aligned as the default.
