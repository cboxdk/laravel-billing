# ADR-0016 — Plan retirement and sunset (forced migration off a plan by a cutoff)

**Status:** Accepted (2026-07-18)

## Context

A catalog plan today is either `offered` (in the current catalog, a valid transition
source and target) or `legacy` (grandfathered — no longer offered to new subscribers, a
valid transition **source** but never a **target**, so once left it cannot be returned
to — ADR-0010). `legacy` expresses *not-offered-to-new*, but it says nothing about
*getting existing subscribers off the plan*: a legacy plan may be held **indefinitely**.

That is exactly wrong for an **early-access / beta / demo** plan, or any plan a business
must genuinely **discontinue**. Such a plan needs a **hard cutoff**: a date after which it
is retired and its existing subscribers must move off it — migrate to a successor, cancel,
or fall to a configured default — rather than continue on a plan the business no longer
runs. There is currently no notion of a *dated, forced* migration off a plan, and no
first-class outcome for "the subscriber never chose" that a host can act on. Absent that,
a discontinued plan either silently keeps renewing (billing customers for a plan that no
longer exists) or is torn away mid-period (destroying paid-for time). Both are wrong.

The retirement must also respect the invariants already in place: a migration onto a
successor is still a plan change and must be **validated through the `TransitionPolicy`**
(ADR-0010), never a silent jump across families; and no subscriber should ever lose paid
time they have already been charged for.

## Decision

Introduce a first-class **plan retirement / sunset**.

- **A plan may carry an optional `PlanRetirement`** — `{DateTimeImmutable $retiresAt,
  ?string $defaultSuccessorPlanId}`. It rides on a **trailing, optional** constructor
  argument of `Product` (BC-safe: every existing product construction is unchanged). A new
  `PlanStatus::Retiring` labels the intent (offered-no-more **and** carrying a cutoff);
  retiring-ness is otherwise **computed** from the `PlanRetirement` so the two never drift.
  `Product::isRetiring(DateTimeImmutable $at)`, `Product::retiresAt()`, and
  `Product::isBeingRetired()` read it.

- **A being-retired plan is never a valid transition target.** Like a legacy plan it has no
  inbound edge — no subscription may switch *onto* a plan that is itself being sunset. The
  `FamilyTransitionPolicy` refuses it with a caller-facing reason, alongside the existing
  legacy refusal.

- **Resolution happens at the subscriber's next renewal on/after `retiresAt`.** A pure
  `PlanRetirementResolver` maps `(Subscription, Catalog, now)` to a `RetirementResolution`
  — a tagged sum type over six mutually-exclusive outcomes:
  - **`NotRetiring`** — the plan carries no retirement, or `now` is **before** the cutoff:
    the plan is still valid at this renewal, nothing is forced, the subscriber keeps their
    paid time and renews normally.
  - **`ResolvedToSuccessor(planId)`** — the subscriber **scheduled a successor plan** (a
    scheduled plan change onto another product): migrate to it, validated through the
    `TransitionPolicy`.
  - **`ResolvedToCancel`** — the subscriber **scheduled a cancel**: cancel at that renewal.
    They keep serving until then. Cancellation is a **first-class, equal choice**, not a
    fallback.
  - **`RetiringChooseBy(renewalDueDate, defaultSuccessorPlanId)`** — retired, no choice yet,
    but the subscriber still has **paid time left** (the renewal is not yet due): they must
    resolve by `renewalDueDate` (their next boundary on/after the cutoff, computed from the
    billing period / cycle). Informational — nothing is enacted yet.
  - **`ResolvedToDefault(planId)`** — retired, renewal due, **no choice**, but the retirement
    configures a `defaultSuccessorPlanId`: migrate to it (also validated through the policy).
  - **`UnresolvedRetirement`** — retired, renewal due, no choice, **no default**:
    **deny-by-default**. The renewal does **not** silently continue on a retired plan; it
    yields an outcome the host must surface. A retired plan is never auto-charged.

- **Enactment is a thin `RetirementRenewalPolicy` a host calls in place of
  `SubscriptionManager::renew()`** for subscriptions that may be on a retiring plan. It
  resolves, then enacts: a plain renewal for `NotRetiring` / `RetiringChooseBy` / (the
  already-scheduled cancel in) `ResolvedToCancel`; a policy-validated migration for
  `ResolvedToSuccessor` / `ResolvedToDefault` (an illegal successor raises
  `TransitionNotAllowed` — never a silent migration); and a refusal (`RetirementNotResolved`)
  for `UnresolvedRetirement`. It owns **no arithmetic**: every state change is delegated to
  the `SubscriptionManager` (a new `renewOntoPlan()` migrates + advances in one step), so the
  state machine stays the single source of truth and **the existing `renew()` behaviour for
  non-retiring plans is unchanged**.

- **No grandfathering by default.** Retirement is a *forced* migration keyed on a date; a
  plan a business wants held indefinitely is `legacy`, not `retiring`. The two are distinct
  and complementary.

## Consequences

- A business can genuinely **discontinue** a plan on a date, with existing subscribers
  resolved off it at their next renewal — migrate, cancel, or default — and a host-surfaced
  `unresolved-retirement` when someone never chose, instead of silently billing a dead plan.
- **No one loses paid time.** Resolution lands on the next renewal on/after the cutoff, so a
  mid-period subscriber serves out the time they paid for and only then resolves.
- The **transition policy remains the one gate** on plan changes: a retirement migration is
  still validated (family / edge / legacy / being-retired), so a sunset can never smuggle a
  subscriber across an illegal boundary.
- A **being-retired plan cannot be a target**, so a host cannot accidentally offer, or a
  subscriber accidentally choose, a plan that is on its way out.
- `ScheduledChange` gained an optional `newProductId`, so a scheduled change can now be a
  **plan** change (not just a price change); `SubscriptionManager::schedulePlanChange()`
  schedules one and `renew()` enacts a due one (product + price), still BC for price-only
  changes.

## Alternatives considered

- **Grandfather forever (only `legacy`).** Rejected: it cannot express a *forced*
  discontinuation. A beta/demo plan that must end has no mechanism to actually get
  subscribers off it — they would renew on it indefinitely.
- **Hard cutoff = immediate lockout at `retiresAt`.** Rejected: it destroys paid-for time —
  a subscriber charged through the end of their period would be cut off mid-period. Resolving
  at the **next renewal on/after the cutoff** lets everyone serve out the time they paid for.
- **Silently continue on the retired plan when no choice is made.** Rejected: it bills
  customers for a plan the business no longer offers, and hides the discontinuation. The
  deny-by-default `UnresolvedRetirement` forces the host to surface the decision instead.
- **Silently auto-migrate everyone to a default with no opt-out.** Rejected as the *only*
  path: a default is offered, but cancellation is a **first-class equal choice** and an
  explicitly scheduled successor wins — the subscriber is never railroaded onto a plan they
  did not pick when they have expressed a different intent.
