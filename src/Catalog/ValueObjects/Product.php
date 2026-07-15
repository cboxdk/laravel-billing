<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

/**
 * A sellable product. Prices are versioned separately (Stripe-style Product/Price
 * split), so a product's price can change over time without changing the product.
 */
readonly class Product
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
