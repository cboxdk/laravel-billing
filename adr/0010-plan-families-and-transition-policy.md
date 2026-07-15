# ADR-0010 — Plan families and transition policy

**Status:** Accepted (2026-07-16)

## Context

A catalog of plans without transition rules lets a subscription change to **any** other
plan — the plan-change flow simply prorates whatever the target is. But some transitions
are nonsensical or unsupported and must be **refused**, not prorated:

- between **deployment models** — `on-prem ↔ hosted` — which imply different fulfilment,
  contracts, or a data migration;
- between incompatible **pricing models** — `pay-as-you-go ↔ unlimited` — where a direct
  in-place switch would misprice or strand accrued usage.

Today the engine has no notion of plan grouping or allowed transitions; `PlanChangePreviewer`
prorates any target, and catalog plans have no family attribute.

## Decision

Introduce **plan families** and a **transition policy**, **deny-by-default** on cross-family
moves.

- Each plan declares a **family** — a stable key (e.g. `hosted`, `on-prem`, `payg`,
  `unlimited`).
- A `TransitionPolicy` contract decides `canTransition(from, to): Decision`
  (`Allowed` | `Disallowed(reason)`). The batteries-included `FamilyTransitionPolicy`:
  - **same family → allowed**;
  - **across families → allowed only along an explicitly declared edge** (an upgrade path,
    optionally carrying guidance like "requires migration");
  - **everything else → refused**. So `payg → unlimited` and `on-prem → hosted` are blocked
    unless an edge is declared.
- The plan-change flow consults the policy **before proration**: a disallowed change returns
  `TransitionNotAllowed(reason)` — never a silent proration. The refusal reason is
  surfaced to the caller (e.g. "requires migration / contact sales").
- `availableTransitions(from)` returns the set of plans the current subscription may switch
  to, so UIs and **upgrade gates** (ADR-0009) only ever offer valid targets.
- **Grandfathered / legacy plans.** A plan can be `offered` (in the current catalog) or
  `legacy` (grandfathered — still held by existing subscribers but no longer offered). A
  legacy plan is a valid transition **source but not a target** — it has no inbound edge, so
  once left it cannot be returned to. The preview therefore carries an **irreversibility
  warning** when the *current* plan is legacy ("you're on a legacy plan; changing means you
  cannot switch back to it") so the customer confirms with eyes open.

## Consequences

- Catalog plans gain a `family`; the engine ships the `TransitionPolicy` contract +
  `FamilyTransitionPolicy` default; `PlanChangePreviewer` and the change command gate on it.
- The app configures families + allowed cross-family edges; the management API exposes
  `availableTransitions`; the SDK surfaces the allowed set and the refusal reason; upgrade
  gates deep-link only to allowed plans.
- Deny-by-default: a plan whose family has no declared edge to the target cannot be
  transitioned to across families — the invariant lives with the plan-change logic, so every
  consumer is protected, not just the app UI.

## Alternatives considered

- **Enforce transitions only in the app.** Rejected — the invariant belongs with the
  plan-change logic so every consumer (API, SDK, jobs) is protected uniformly.
- **A free-form predicate only.** Kept as the contract, but shipped with the family-graph
  policy as the default so families/edges are declarative, not code.
