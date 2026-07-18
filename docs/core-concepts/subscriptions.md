---
title: Subscriptions & proration
description: The rolling subscription lifecycle, mutable scheduled changes, preview-equals-charge proration with per-line rounding, forfeiture on the leave-without-landing transition, and fixed-term (registrar-style) products.
weight: 25
---

# Subscriptions & proration

The module hosts **two product shapes** ([ADR-0015](../../adr/0015-product-shapes-and-term-based-products.md)):
the **rolling `Subscription`** below, and the **fixed-term `TermSubscription`** for
registrar-style products ([jump](#fixed-term-registrar-style-products-adr-0015)).

A `Subscription` pins a plan/price version, an org, and a `BillingPeriod`.
`SubscriptionManager` is the pure state machine over it; `SubscriptionLifecycle`
wires lifecycle transitions to side effects such as wallet forfeiture.

## Lifecycle

```php
$sub = $manager->create($id, $org, $productId, $priceId, $period);
$sub = $manager->cancelAtPeriodEnd($sub);   // → NonRenewing; capacity retained until period end
$sub = $manager->resume($sub);              // NonRenewing → Active (clear the pending cancel)
$sub = $manager->cancelNow($sub);           // immediate → Canceled
$sub = $manager->scheduleChange($sub, $newPriceId, $effectiveAt); // staged, mutable
$sub = $manager->clearScheduledChange($sub);
$sub = $manager->renew($sub, $nextPeriod);  // applies a due scheduled change, or enacts a due cancel
```

A **scheduled change** (`ScheduledChange`) is staged and remains **mutable /
cancelable until its effective date** — a downgrade at period end moves no money
now and keeps paid capacity until then. `renew` applies a due change or, if the
subscription is `NonRenewing`, transitions it to `Canceled`.

## State machine

`SubscriptionStatus` is a first-class state machine — the original `Active`/`Canceled`
pair extended **additively** with the states a real subscription passes through:

| State | Serving? | Meaning |
| --- | --- | --- |
| `Trialing` | yes | Created with a trial; no charge yet. Converts at trial end. |
| `Active` | yes | The normal paying state. |
| `PastDue` | yes | A payment failed; still serving during dunning. |
| `Paused` | no | Temporarily suspended; **no billing** while paused. |
| `NonRenewing` | yes | A period-end cancellation is scheduled; still serving until it renews. |
| `Canceled` | no | Terminal; the org is on no plan. |

`isActive()` is true for the four **serving** states (so entitlement projection and
forfeiture treat a `NonRenewing` or `PastDue` subscription as still holding its plan);
it is false for `Paused` and `Canceled`.

Transitions are **deny-by-default**: `SubscriptionManager` checks every status change
against its allowed-transition table and throws
`Cbox\Billing\Subscription\Exceptions\IllegalStateTransition` on an illegal one
(resuming a canceled subscription, taking a paused one past-due, …) rather than
applying it silently. The allowed moves:

```
create ─▶ Trialing (with a trial)   or   Active (without)
Trialing   ─▶ Active | Paused | PastDue | NonRenewing | Canceled
Active     ─▶ PastDue | Paused | NonRenewing | Canceled   (and Active, idempotent)
PastDue    ─▶ Active | NonRenewing | Canceled
Paused     ─▶ Active | Canceled
NonRenewing─▶ Active | Canceled
Canceled   ─▶ (terminal)
```

```php
$sub = $manager->markPastDue($sub);  // payment failed  → PastDue
$sub = $manager->recover($sub);      // payment recovered → Active
$sub = $manager->pause($sub, $at);   // → Paused (records the pause instant)
$sub = $manager->resume($sub, $at);  // Paused → Active, period shifted by the paused span
```

`renew` **honours the non-billing states**: a `Paused` or `Canceled` subscription is
returned untouched (no period advance, no charge), and a `NonRenewing` one is enacted
to `Canceled`.

## Trials

A subscription can begin in a **trial** — pass a `trialEndsAt` to `create` (or use the
explicit `startTrial`). It opens `Trialing`, carries the trial end, and **charges
nothing** during the trial. At trial end, `convertTrial` moves it to `Active` (the
first charge is raised by the invoice/renewal path that observes the transition):

```php
$sub = $manager->startTrial($id, $org, $productId, $priceId, $period, $trialEndsAt); // Trialing
$sub = $manager->convertTrial($sub, $at);   // Trialing → Active, first charge
```

A host that requires a payment method or commitment before charging can, per its own
policy, route trial end to a non-serving state (`cancelNow`, or `pause`) instead of
converting.

## Ramp deals

A **ramp** steps the recurring price over a contract's term — a *predetermined*
schedule of price changes rather than one scheduled by hand. A `RampSchedule` is an
ordered set of `RampStep`s (`{fromPeriodIndex, Money}`); the step covering a period is
the one with the greatest `fromPeriodIndex ≤ index`, so the amount holds until the next
boundary:

```php
$ramp = new RampSchedule([
    new RampStep(0, Money::ofMinor(10000, 'USD')),  // 100.00 for periods 0–2
    new RampStep(3, Money::ofMinor(15000, 'USD')),  // 150.00 from period 3 on
]);
$sub = $manager->withRamp($sub, $ramp);
```

Each `renew` advances the subscription's `periodIndex`, and
`effectiveRecurringAmount()` resolves the ramp step for the current index — so the
same subscription charges 100.00 through the first three periods and 150.00
thereafter, with no per-period scheduling. It composes with `scheduleChange`
(price-id changes) independently: the ramp drives the recurring *amount* by index,
scheduled changes re-pin the *price id*.

## Minimum commitments & true-up

A `MinimumCommitment` is a minimum spend per billing period. At period close, if the
period's actual charged amount (recurring + metered) falls **below** the minimum, a
**true-up** line bills the shortfall so the commitment is met. The math is the pure,
remainder-safe `TrueUpCalculator` the invoice/renewal path calls:

```php
TrueUpCalculator::shortfall(Money $minimum, Money $actual): Money  // max(minimum − actual, 0)

$sub = $manager->withMinimumCommitment($sub, new MinimumCommitment(Money::ofMinor(10000, 'USD')));
$sub->trueUp(Money::ofMinor(7000, 'USD'));   // 30.00 shortfall → bill it
$sub->trueUp(Money::ofMinor(12000, 'USD'));  // 0 — the floor was already met
```

Everything is integer minor units, so the true-up is exact; a currency mismatch
between the floor and the actual amount is refused by `Money`.

## Preview equals charge (ADR-0007)

A plan-change or renewal **preview** must equal what is actually **charged**, to
the cent — it is a promise shown at the confirm step. Two things break that:

- **Separate code paths** for preview and charge drift apart.
- **Rounding a combined total** diverges from the settlement gateway, which rounds
  each invoice line independently.

So **one function** — `ProrationCalculator` — computes the proration/quote, and
both the previewer (`PlanChangePreviewer`) and the charger call it. Identical
inputs yield an identical `Proration` *by construction*, not by parallel
maintenance. The previewer adds no arithmetic of its own.

Each line is **rounded independently** to whole minor units (matching the gateway,
selected by `GatewayRounding`) before summing. The calculator is explicit about
the edges:

- **Anchor** (`AnchorMode`): keep-anchor prorates the delta to renewal;
  reset-anchor charges a fresh period minus unused base and **can net a credit**.
- **Deferred downgrades** move no money.
- **Entering from pay-as-you-go** charges a full fresh period with no credit.
- A proration instant **before the period start** is clamped.
- A **zero-length period** does not divide by zero.

Preview↔charge parity is testable directly: same inputs → same object. See
[ADR-0007](../../adr/0007-preview-equals-charge.md).

## Forfeiture on transition (ADR-0006)

Ending a subscription must forfeit the right credit pools. Forfeiture is keyed on
the **transition** — an org **leaving a subscription without landing on another**,
which covers *cancel-to-null* — not on a specific destination plan. A
`SubscriptionTransition` drives the `ForfeitureHandler`:

```php
interface ForfeitureHandler
{
    public function onTransition(SubscriptionTransition $transition, int $now): RemovalReport;
}
```

`WalletForfeiture` implements it against the [wallet](wallets.md): only
`forfeitsOnCancel` pools are affected and each is floored at zero, so a negative
pay-as-you-go balance cannot offset a forfeitable allotment. See
[ADR-0006](../../adr/0006-credit-lots-expiry-forfeiture.md).

## Fixed-term (registrar-style) products (ADR-0015)

A `FixedTerm` product — a domain, a hosting term, a certificate — is bought for a
committed [`Term`](catalog-and-pricing.md#term--price-kind-pricing-adr-0015) and
billed as a **`TermSubscription`**: `{id, orgId, productId, instanceRef, term,
registeredAt, termEndsAt, autoRenew, status}`, where `instanceRef` is the resource
(the domain name). One org holds **many** instances, including many of the **same**
product, each with its own term end and status.

`TermLifecycle` is the pure registrar state machine over an instance, its product's
`RegistrarWindows` (a `Term` of grace + a `Term` of redemption), and an instant:

```php
$phase = $lifecycle->phaseAt($instance, $windows, $now); // Active|Grace|Redemption|Expired
$renewed  = $lifecycle->renew($instance, new Term(1, TermUnit::Year), $now);   // → Active
$redeemed = $lifecycle->redeem($instance, new Term(1, TermUnit::Year), $now);  // Redemption → Active
$out      = $lifecycle->transferOut($instance, $now);                          // → TransferredOut
$fresh    = $lifecycle->transferIn($id, $org, $productId, $ref, $term, $now);  // new Active
```

**Phase boundaries** (inclusive upper edge): `Active` while `now ≤ termEndsAt`;
`Grace` while `now ≤ termEndsAt + grace`; `Redemption` while `now ≤ termEndsAt +
grace + redemption`; otherwise `Expired`. `renew` extends `termEndsAt` by the new
term from **the later of `now` / `termEndsAt`** (early renewal stacks; late renewal
extends from now). `TransferredOut` and `Cancelled` are terminal — `phaseAt`
preserves them.

**Auto-renew boundary:** with `autoRenew = true`, passing the term end does **not**
enter `Grace` — the instance stays `Active` and `isAutoRenewalDue` reports a renewal
is due, so a billing run charges the `Renewal` price and extends the term. `Grace` is
the manual-lapse path only.

**Purchasing** goes through the same pipeline as everything else. `TermPurchase`
selects the (term × kind) price via `Catalog::termPriceFor` and produces the
`LineInput` the shared `QuoteBuilder` taxes and applies credit to; the `Invoicer`
issues it. Register / Renewal / Redemption / Transfer are just a choice of
`PriceKind` — tax, seller-of-record, credits, and dunning are unchanged.

```php
$quote = $termPurchase->quote($product, new Term(2, TermUnit::Year), PriceKind::Register, 1, $context, $now);
```

> The **registry/EPP/DNS** integration — auth codes, actual provisioning, real
> transfer orchestration — is **out of scope**: a connector concern. The engine owns
> only the **commercial** lifecycle and the money movements it implies.

## Testing

`Cbox\Billing\Subscription\Testing\InteractsWithSubscriptionLifecycle` and
`FakeForfeitureHandler` drive lifecycle transitions and assert the forfeiture
fired on the right transition.

## Related

- [Plan retirement & sunset](plan-retirement.md)
- [Cookbook: preview a plan change](../cookbook/preview-a-plan-change.md)
- [Quotes & invoicing](quotes-and-invoicing.md)
- [Wallets & credits](wallets.md)
