<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Contracts;

use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Catalog\ValueObjects\Term;
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
}
