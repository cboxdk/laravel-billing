---
title: Catalog & pricing
description: Versioned products and prices with grandfathering, coupons that discount the net before tax, and seller-of-record routing that drives tax.
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
