---
title: Reporting & SaaS metrics
description: Pure read-model calculators over host-supplied subscriptions and periods — MRR/ARR, the MRR movement waterfall (new/expansion/contraction/churn/reactivation), net & gross revenue retention, and cohort retention. Exact minor-unit maths, per currency.
weight: 32
---

# Reporting & SaaS metrics

The `Reporting` module is a set of **pure, stateless read-model calculators**. It
owns no tables and reads no store: the host supplies the subscriptions, amounts and
periods, and the calculators return value objects. Every total is computed in exact
integer **minor units** (never floats) and **per currency** — revenue in different
currencies is never summed into one figure.

All calculators are bound as singletons in `ReportingServiceProvider`; resolve them
from the container and depend on the class.

| Calculator | Answers |
| --- | --- |
| `MrrCalculator` | MRR and ARR per currency, with the state->MRR policy |
| `MrrMovement` | The MRR bridge between two periods, decomposed into five components |
| `RetentionCalculator` | Net & gross revenue retention (NRR/GRR) |
| `CohortRetention` | A cohort x age matrix of retained count and retained MRR |
| `ChurnCalculator` | Customer churn rate over a period |

## MRR and the state->MRR policy

`MrrCalculator::summarize(iterable<Money>)` sums pre-normalised monthly amounts;
`summarizeSubscriptions(iterable<SubscriptionMrr>)` takes status-tagged amounts and
applies the policy for you, so callers do not pre-filter by status. ARR is always
`MRR x 12`.

A subscription counts toward MRR only while it is actually being charged for its
plan:

| Status | Contributes to MRR? | Why |
| --- | --- | --- |
| `Active` | **Yes** | The normal paying state |
| `PastDue` | **Yes** | Still serving under dunning; the charge is owed, not gone |
| `NonRenewing` | **Yes** | Still bills its final period until it renews into the cancel |
| `Trialing` | No (0) | A trial is not yet revenue — counts once it converts |
| `Paused` | No (0) | Billing is suspended while paused |
| `Canceled` | No (0) | Terminal; the org is on no plan |

This is deliberately **stricter than entitlement serving**
(`SubscriptionStatus::isServing()`), which also grants access while `Trialing`: a
trial *serves the plan* but is *not MRR*. The predicate is exposed as
`MrrCalculator::contributes(SubscriptionStatus)`.

## MRR movement (the waterfall)

`MrrMovement::waterfall(iterable<SubscriptionMovement>)` decomposes the MRR change
between two periods into the five standard components, per currency. Each
`SubscriptionMovement` carries one subscription's `startMrr` and `endMrr` (same
currency; a subscription absent at a point contributes `Money::zero`), plus a
`returning` flag that distinguishes a brand-new logo from a returning customer.

Classification per subscription:

| Transition | Component |
| --- | --- |
| `start = 0`, `end > 0`, not returning | **new** (the full end amount) |
| `start = 0`, `end > 0`, returning | **reactivation** (the full end amount) |
| `start > 0`, `end = 0` | **churn** (the full start amount, as a magnitude) |
| `start > 0`, `end > start` | **expansion** (the increase) |
| `start > 0`, `0 < end < start` | **contraction** (the decrease, as a magnitude) |
| `start > 0`, `end = start` | no movement |

`contraction` and `churn` are stored as **positive magnitudes**; the accounting
identity subtracts them. For every currency the `MrrWaterfall` satisfies exactly:

```
startMrr + new + expansion - contraction - churn + reactivation = endMrr
```

`MrrWaterfall::reconciles()` asserts this holds (it does by construction), and
`netChange()` returns `endMrr - startMrr`.

### ARR waterfall

`MrrWaterfall::toArr()` returns an `ArrWaterfall` — the same decomposition with every
component scaled by 12. Because `x12` is a linear scaling of exact minor-unit amounts,
the identity carries over unchanged: `startArr + ... = endArr`.

## Net & gross revenue retention

`RetentionCalculator` measures how much of a **starting cohort's** MRR is kept,
computed from exact minor-unit sums:

```
NRR = (start + expansion - contraction - churn) / start
GRR = (start - contraction - churn) / start
```

Both **exclude new logos and reactivations** — they have no starting MRR in the
cohort, so they fall out by construction. GRR omits the expansion term, so
`GRR <= NRR` always.

- `forCohort(Money $start, Money $expansion, Money $contraction, Money $churn)` — raw
  magnitudes.
- `fromWaterfall(MrrWaterfall)` — reuses a waterfall's start/expansion/contraction/churn.

The result is a `RetentionRates` holding two `RetentionRatio` fractions. A
`RetentionRatio` keeps the exact `numerator`/`denominator` (retained vs starting MRR
in minor units) and derives `basisPoints()` on demand (10000 = 100%), rounded half
away from zero — so there is no float drift. A non-positive denominator (no cohort to
retain) is `isDefined() === false` and reports `0` rather than dividing by zero.

> Worked example: start `100000`, `+20000` expansion, `-5000` contraction, `-10000`
> churn -> NRR `105000/100000` = **10500 bps (105%)**, GRR `85000/100000` = **8500 bps
> (85%)**.

## Cohort retention

`CohortRetention::matrix(list<string> $periods, iterable<SubscriptionPeriodMrr>)`
groups subscriptions by the period they started in (their **cohort**) and, for each
cohort, reports the retained subscription **count** and retained **MRR** at that
period and every later one — a cohort x age matrix.

Each `SubscriptionPeriodMrr` carries an `mrrByPeriod` array aligned positionally to
`$periods` (a period contributing nothing is `Money::zero`). A subscription is
**retained** at a period when its MRR there is positive. Each cohort is
single-currency (a cohort total never mixes currencies — mixed input is rejected).

The result is a `CohortMatrix` of `CohortRow`s (ordered by cohort label), each with
`initialCount`/`initialMrr` (age 0) and a list of `CohortCell`s (ordered by period)
carrying `periodIndex`, `age` (offset from the cohort's start), `retainedCount` and
`retainedMrr`. Ordering is deterministic throughout.

## Honest scope

These calculators are **arithmetic over data the host feeds them** — they do not
snapshot MRR over time, persist cohorts, or reconcile against the ledger. The host
decides what a "period" is, supplies each subscription's monthly-equivalent amount
(annual plans divided upstream; metered usage is not MRR), and stores the results if
it wants history. Money is always minor units, per currency, and remainder-safe.
