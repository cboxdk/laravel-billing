---
title: Catalog & pricing
description: Versioned products and prices with grandfathering, product shapes, term × price-kind pricing for registrar-style products, coupons that discount the net before tax, and seller-of-record routing that drives tax.
weight: 28
---

# Catalog & pricing

## Catalog

A Stripe-style split of `Product` (what you sell) and `Price` (how much / how
often — a version):

```php
interface Catalog
{
    // resolve, list, and version products and prices
}
```

`InMemoryCatalog` is the default. Prices are **versioned by effective date**: a new
version supersedes the prior, new subscriptions use the most recent version, and
existing subscriptions are invoiced against the version **in effect when the
subscription was created** — price-pinning / **grandfathering** by default.

## Pricing models

`PricingModel` selects how a quantity turns into an amount. Flat and per-unit are the
scalar models; the four **tiered** models price against an ordered list of
`PriceTier`s carried on the `Price` (and, for packages, a `packageSize`):

| Model | Rule |
| --- | --- |
| `Flat` | one fixed amount regardless of quantity (billable quantity is 1) |
| `PerUnit` | `unitAmount × quantity` |
| `Graduated` | the quantity is sliced across tiers; **each slice is priced at its own tier's rate** and the slices are summed (plus any per-tier flat entry fee reached) |
| `Volume` | **all** units are priced at the single tier the **total** quantity lands in (a retroactive volume discount) |
| `Package` | `ceil(quantity ÷ packageSize)` whole blocks, each charged the block's flat price (buy in packs of N) |
| `Stairstep` | a single flat amount for the whole bracket the quantity lands in |

A `PriceTier` is `{ ?int upTo, Money unitAmount, ?Money flatAmount }`: `upTo` is the
tier's inclusive upper bound in units (`null` marks the final, unbounded tier),
`unitAmount` the per-unit rate within the tier, and `flatAmount` a tier fee whose
meaning is set by the model (a graduated/volume entry fee, the package block price,
or the stairstep bracket price).

```php
// Graduated: 0–10 @ €1.00, 11–100 @ €0.80, 101+ @ €0.50
$price = new Price('metered-v1', 'metered', PricingModel::Graduated,
    Money::ofMinor(0, 'EUR'), new DateTimeImmutable('2025-01-01'),
    tiers: [
        new PriceTier(10,   Money::ofMinor(100, 'EUR')),
        new PriceTier(100,  Money::ofMinor(80,  'EUR')),
        new PriceTier(null, Money::ofMinor(50,  'EUR')),
    ],
);

$catalog->priceQuantity('metered', 150, $now); // €107.00 → 10×100 + 90×80 + 50×50
```

Every tiered model is computed by `Pricing\TierCalculator` in integer **minor units**
— only `unitAmount × wholeUnits` and `flatAmount` terms, never a division — so no
minor unit is ever rounded away (**remainder-safe** by construction). It is
**deny-by-default**: an empty, mis-ordered, negatively-priced, or gap-having tier
set, a package with no positive size/block price, or a quantity that no tier covers
raises `MalformedTierSet` rather than silently returning zero. `priceQuantity()` is
the single catalog entry point the quote path uses, so a tiered product prices
through the same call as a flat one; `Price::amountFor()` does the same for a `Price`
in hand. Tiered pricing composes with metering: a meter's aggregated billable
quantity (see [Metering → billable-metric aggregations](metering.md#billable-metric-aggregations))
feeds straight into `amountFor()`.

## Product shapes (ADR-0015)

A `Product` declares a `ProductShape` selecting its billing/fulfilment semantics:

- **`Metered`** — a usage-metered plan (entitlement + real-time metering).
- **`Recurring`** — a rolling [subscription](subscriptions.md): cycle-anchored,
  prorated, renewing indefinitely. This is the **default**, so pre-shape catalogs
  keep their exact meaning.
- **`FixedTerm`** — a registrar-style product bought for a committed `Term` (1/2/5
  yr) with distinct register/renewal/transfer/redemption pricing and a post-expiry
  lifecycle. See [Subscriptions → fixed-term products](subscriptions.md#fixed-term-registrar-style-products-adr-0015).
- **`OneTime`** — a single non-recurring charge.

`shape` is the **last constructor parameter** and defaults to `Recurring`, so it is
backward-compatible.

## Term × price-kind pricing (ADR-0015)

A `FixedTerm` product's catalog is a set of **(term × kind) price points**. A `Term`
is a `{count, TermUnit}` (`Day | Month | Year`) with calendar arithmetic (`addTo`,
`toIso8601` → e.g. `P2Y`, `equals`). A `PriceKind` is `Standard | Register |
Renewal | Transfer | Redemption` — a `.com` at `P2Y`/`Register` is a different
number than at `P1Y`/`Renewal`, and redemption carries a recovery premium.

```php
$price = $catalog->termPriceFor('domain-com', new Term(2, TermUnit::Year), PriceKind::Register, $now);
```

`termPriceFor` grandfathers by effective date exactly like `priceFor`: an instance
registered before a price rise keeps the version effective at its registration.
`priceFor` resolves only the non-term (`Standard`) prices, so the two never collide
on a mixed catalog. Recurring/metered prices leave `term` null and `kind` at
`Standard`.

> Registry/EPP/DNS provisioning (auth codes, actual transfers) is **out of scope** —
> a connector concern. The catalog owns only the commercial price grid.

## Pricing operations

`Coupon` (`DiscountType::Percentage` or `Fixed`, with validity) and `CouponApplier`
discount the **net before tax**, so the tax base is the discounted amount:

```php
$net = $couponApplier->apply($coupon, $net, $now);
```

## Seller of record

Tax depends on *who is selling*. A `SellerEntity` carries its tax registrations
(`TaxRegistration`), and `EntityRouter` routes a buyer to the entity registered in
their country, falling back to a default:

```php
interface EntityRouter
{
    // route a buyer to the SellerEntity that should be the seller of record
}
```

`DefaultEntityRouter` is the multi-entity routing that drives tax resolution in the
[quote](quotes-and-invoicing.md), which composes `cboxdk/laravel-tax` for the
actual per-line VAT.

## Related

- [Quotes & invoicing](quotes-and-invoicing.md)
- [Subscriptions & proration](subscriptions.md)
