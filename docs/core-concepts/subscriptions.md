---
title: Subscriptions & proration
description: The subscription lifecycle, mutable scheduled changes, preview-equals-charge proration with per-line rounding, and forfeiture on the leave-without-landing transition.
weight: 25
---

# Subscriptions & proration

A `Subscription` pins a plan/price version, an org, and a `BillingPeriod`.
`SubscriptionManager` is the pure state machine over it; `SubscriptionLifecycle`
wires lifecycle transitions to side effects such as wallet forfeiture.

## Lifecycle

```php
$sub = $manager->create($id, $org, $productId, $priceId, $period);
$sub = $manager->cancelAtPeriodEnd($sub);   // flag; capacity retained until period end
$sub = $manager->resume($sub);              // clear the flag
$sub = $manager->cancelNow($sub);           // immediate Canceled
$sub = $manager->scheduleChange($sub, $newPriceId, $effectiveAt); // staged, mutable
$sub = $manager->clearScheduledChange($sub);
$sub = $manager->renew($sub, $nextPeriod);  // applies a due scheduled change, or cancels if flagged
```

A **scheduled change** (`ScheduledChange`) is staged and remains **mutable /
cancelable until its effective date** — a downgrade at period end moves no money
now and keeps paid capacity until then. `renew` applies a due change or, if the
subscription was flagged to cancel at period end, transitions it to `Canceled`.

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

## Testing

`Cbox\Billing\Subscription\Testing\InteractsWithSubscriptionLifecycle` and
`FakeForfeitureHandler` drive lifecycle transitions and assert the forfeiture
fired on the right transition.

## Related

- [Cookbook: preview a plan change](../cookbook/preview-a-plan-change.md)
- [Quotes & invoicing](quotes-and-invoicing.md)
- [Wallets & credits](wallets.md)
