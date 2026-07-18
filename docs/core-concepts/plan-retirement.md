---
title: Plan retirement & sunset
description: Retire a plan on a hard cutoff and force existing subscribers off it at their next renewal — migrate to a successor, cancel, or fall to a configured default, with a deny-by-default outcome when no choice is made.
weight: 26
---

# Plan retirement & sunset

A plan status of `Legacy` marks a plan **not offered to new subscribers** — but a legacy
plan may be held **indefinitely**. That is wrong for an **early-access / beta / demo** plan,
or any plan a business must genuinely **discontinue**: those need a **hard cutoff** by which
existing subscribers are moved off. Plan retirement ([ADR-0016](../../adr/0016-plan-retirement-and-sunset.md))
adds that — a *forced* migration off a plan by a date, with **no paid time lost**.

## The cutoff

A plan carries an optional `PlanRetirement`:

```php
use Cbox\Billing\Catalog\ValueObjects\PlanRetirement;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Catalog\Enums\PlanStatus;

$beta = new Product(
    id: 'beta',
    name: 'Early access',
    family: 'hosted',
    status: PlanStatus::Retiring,
    retirement: new PlanRetirement(
        retiresAt: new DateTimeImmutable('2026-06-01'),
        defaultSuccessorPlanId: 'hosted-pro',   // optional
    ),
);
```

- **`retiresAt`** — the cutoff. From this instant the plan is **retired**.
- **`defaultSuccessorPlanId`** — where a subscriber who makes **no choice** lands (optional).

`PlanStatus::Retiring` labels the intent; retiring-ness is otherwise **computed** from the
`PlanRetirement`, so the two never drift. A retiring plan is **never a valid transition
target** — no subscription may switch *onto* a plan that is being sunset (the
`FamilyTransitionPolicy` refuses it, like a legacy target).

`Retiring` differs from `Legacy` in that it is **forced**: a legacy plan may be held
forever; a retiring plan has a dated cutoff that resolves existing subscribers off it.

## Resolve at the next renewal on/after the cutoff

Retirement is enacted at the subscriber's **next renewal on/after `retiresAt`** — never
mid-period — so no one loses time they already paid for. The pure `PlanRetirementResolver`
maps `(Subscription, Catalog, now)` to one of six outcomes:

| Outcome | When | Effect |
| --- | --- | --- |
| `NotRetiring` | No retirement, or `now` is **before** the cutoff | Renew normally |
| `ResolvedToSuccessor` | The subscriber **scheduled a successor plan** | Migrate to it (policy-validated) |
| `ResolvedToCancel` | The subscriber **scheduled a cancel** | Cancel at the renewal |
| `RetiringChooseBy` | Retired, no choice yet, **paid time remains** | Informational: choose by the renewal-due date |
| `ResolvedToDefault` | Retired, renewal due, no choice, a **default** is set | Migrate to the default (policy-validated) |
| `UnresolvedRetirement` | Retired, renewal due, no choice, **no default** | **Refused** — deny-by-default |

```php
$resolution = $resolver->resolve($subscription, $catalog, $now);
$resolution->outcome;          // a RetirementOutcome
$resolution->successorPlanId;  // for ResolvedToSuccessor / ResolvedToDefault
$resolution->renewalDueDate;   // the deadline, for RetiringChooseBy
```

### The three choices, plus a default and a deny

- **Migrate to a successor.** The subscriber schedules a plan change onto another product —
  `SubscriptionManager::schedulePlanChange($sub, $newProductId, $newPriceId, $effectiveAt)`.
  At the forcing renewal this resolves to `ResolvedToSuccessor` and migrates onto the chosen
  plan and price.
- **Cancel.** The subscriber schedules a period-end cancel (`cancelAtPeriodEnd`) — a
  **first-class, equal choice**, not a fallback. They keep serving until the renewal, then
  end (`ResolvedToCancel`).
- **Do nothing → default.** With no choice, a configured `defaultSuccessorPlanId` catches
  them (`ResolvedToDefault`).
- **Do nothing, no default → deny.** With no choice **and** no default, the renewal yields
  `UnresolvedRetirement`: the plan does **not** silently keep renewing (and charging). The
  host must surface the decision.

## Enact it: the renewal policy

`RetirementRenewalPolicy` is a thin seam a host calls **in place of**
`SubscriptionManager::renew()` for subscriptions that may be on a retiring plan. It resolves,
then enacts:

```php
use Cbox\Billing\Subscription\Retirement\RetirementRenewalPolicy;
use Cbox\Billing\Subscription\Retirement\Exceptions\RetirementNotResolved;

try {
    $renewed = $policy->renew($subscription, $catalog, $nextPeriod, $now);
} catch (RetirementNotResolved $e) {
    // Unresolved retirement — surface it; do NOT renew or charge the retired plan.
} catch (\Cbox\Billing\Subscription\PlanChange\Exceptions\TransitionNotAllowed $e) {
    // The successor is an illegal target for this subscription — surface the reason.
}
```

- `NotRetiring`, `RetiringChooseBy`, and `ResolvedToCancel` renew normally (the last enacts
  the already-scheduled cancel) — **behaviour identical to calling `renew()` directly**, so
  non-retiring subscriptions are completely unaffected.
- `ResolvedToSuccessor` / `ResolvedToDefault` migrate onto the successor, **validated through
  the [`TransitionPolicy`](subscriptions.md)** ([ADR-0010](../../adr/0010-plan-families-and-transition-policy.md)):
  an illegal successor (e.g. a cross-family jump with no declared edge) raises
  `TransitionNotAllowed` rather than silently migrating.
- `UnresolvedRetirement` raises `RetirementNotResolved` — the renewal is refused, never a
  silent charge on a retired plan.

The policy owns **no arithmetic**: it delegates every state change to the
`SubscriptionManager` (`renewOntoPlan()` migrates and advances the period in one step), so
the state machine stays the single source of truth.

## Testing

`Cbox\Billing\Catalog\Testing\InteractsWithCatalog::retiringPlan()` builds a plan with a
cutoff, and `Cbox\Billing\Subscription\Testing\InteractsWithSubscriptionLifecycle` exposes
`retirementResolver()` and `retirementRenewalPolicy()` to drive resolution and enactment.

## Related

- [Subscriptions & proration](subscriptions.md)
- [Catalog & pricing](catalog-and-pricing.md)
- [ADR-0016 — Plan retirement and sunset](../../adr/0016-plan-retirement-and-sunset.md)
- [ADR-0010 — Plan families and transition policy](../../adr/0010-plan-families-and-transition-policy.md)
