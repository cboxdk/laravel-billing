<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Contracts;

use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\Product;
use DateTimeImmutable;

/**
 * Resolves products and their effective (versioned) prices. Pass the date to pin
 * the price: "now" for a new sale, the subscription's start date to keep a
 * grandfathered subscriber on their original price.
 */
interface Catalog
{
    public function product(string $id): ?Product;

    /** The price version effective for a product at a given date, or null. */
    public function priceFor(string $productId, DateTimeImmutable $at): ?Price;
}
