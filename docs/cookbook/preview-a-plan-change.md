---
title: Preview a plan change
description: Show the customer exactly what an upgrade or downgrade will cost — the same figure the charger will use.
weight: 45
---

# Preview a plan change

The preview and the charge run through the **same** `ProrationCalculator`, so what
you show at the confirm step equals what is charged, to the cent (see
[ADR-0007](../../adr/0007-preview-equals-charge.md)).

## Preview an upgrade

```php
use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\AnchorMode;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;

$preview = $previewer->preview(
    currentPrice: Money::ofMinor(1_000, 'EUR'), // €10.00 current
    newPrice:     Money::ofMinor(3_000, 'EUR'), // €30.00 new
    period:       $period,
    at:           $now,
    context:      $quoteContext,               // place of supply, customer type, seller registrations
    description:  'Upgrade to Pro',
    anchor:       AnchorMode::Keep,            // prorate the delta to renewal
);

$preview->isUpgrade;    // true
$preview->proratedNet;  // Money: the prorated delta
$preview->dueNowQuote;  // Quote: net/tax/gross/credit/dueNow to show at confirm
$preview->newRecurring; // Money: the go-forward recurring amount
$preview->effectiveAt;  // when it takes effect
```

`dueNowQuote` is a full [quote](../core-concepts/quotes-and-invoicing.md) with tax
and any wallet credit applied — display it and charge from the same object.

## Anchors and edges

- `AnchorMode::Keep` prorates the delta to the existing renewal.
- `AnchorMode::Reset` charges a fresh period minus unused base and **can net a
  credit**.
- A **deferred downgrade** (keep-anchor, lower price) moves no money now — schedule
  it with `SubscriptionManager::scheduleChange` instead.
- Entering from pay-as-you-go charges a full fresh period with no credit; a
  pre-period instant is clamped; a zero-length period does not divide by zero.

## Related

- [Subscriptions & proration](../core-concepts/subscriptions.md)
- [Quotes & invoicing](../core-concepts/quotes-and-invoicing.md)
