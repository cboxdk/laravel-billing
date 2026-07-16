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

`InMemoryCatalog` is the default. `PricingModel` covers **flat** and **per-unit**
pricing. Prices are **versioned by effective date**: a new version supersedes the
prior, new subscriptions use the most recent version, and existing subscriptions
are invoiced against the version **in effect when the subscription was created** —
price-pinning / **grandfathering** by default.

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
