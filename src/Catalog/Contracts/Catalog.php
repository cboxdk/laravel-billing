<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Contracts;

use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use DateTimeImmutable;

/**
 * Resolves products and their effective (versioned) prices. Pass the date to pin
 * the price: "now" for a new sale, the subscription's start date to keep a
 * grandfathered subscriber on their original price.
 */
interface Catalog
{
    public function product(string $id): ?Product;

    /**
     * Every product (plan) in the catalog. Used by a {@see TransitionPolicy}
     * to enumerate the plans a subscription may switch to.
     *
     * @return list<Product>
     */
    public function products(): array;

    /** The price version effective for a product at a given date, or null. */
    public function priceFor(string $productId, DateTimeImmutable $at): ?Price;

    /**
     * The effective (grandfathered) price for a fixed-term product's (term × kind)
     * price point at a given date, or null when no such point is offered. This is the
     * term counterpart to {@see priceFor()}: registering a `P2Y` domain reads the
     * `Register` price point, renewing it the `Renewal` one, each pinned by effective
     * date exactly like any versioned price (ADR-0015).
     */
    public function termPriceFor(string $productId, Term $term, PriceKind $kind, DateTimeImmutable $at): ?Price;

    /**
     * The total charge for `$quantity` units of a product's effective (grandfathered)
     * price at `$at`, computed under that price's {@see PricingModel}
     * — flat, per-unit, or a tiered model (graduated / volume / package / stairstep).
     * Null when no non-term price is effective. This is the single entry point the
     * quote path uses to price a (possibly aggregated) quantity without knowing the
     * pricing model, so tiered products flow through the same call as flat ones.
     */
    public function priceQuantity(string $productId, int $quantity, DateTimeImmutable $at): ?Money;
}
