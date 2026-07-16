<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog;

use Cbox\Billing\Catalog\Contracts\Catalog;
use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Catalog\ValueObjects\Term;
use DateTimeImmutable;

/**
 * An in-memory catalog — for tests and small hosts. Effective-date resolution
 * picks the newest price version whose range contains the pin date, which is
 * exactly the grandfathering rule. A database-backed catalog implements the same
 * contract.
 */
readonly class InMemoryCatalog implements Catalog
{
    /** @var array<string, Product> */
    private array $products;

    /** @var array<string, list<Price>> */
    private array $prices;

    /**
     * @param  list<Product>  $products
     * @param  list<Price>  $prices
     */
    public function __construct(array $products = [], array $prices = [])
    {
        $byId = [];
        foreach ($products as $product) {
            $byId[$product->id] = $product;
        }

        $byProduct = [];
        foreach ($prices as $price) {
            $byProduct[$price->productId][] = $price;
        }

        $this->products = $byId;
        $this->prices = $byProduct;
    }

    public function product(string $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function products(): array
    {
        return array_values($this->products);
    }

    public function priceFor(string $productId, DateTimeImmutable $at): ?Price
    {
        $match = null;

        foreach ($this->prices[$productId] ?? [] as $price) {
            if ($price->term !== null) {
                continue;
            }

            if ($price->isEffectiveAt($at) && ($match === null || $price->effectiveFrom > $match->effectiveFrom)) {
                $match = $price;
            }
        }

        return $match;
    }

    public function termPriceFor(string $productId, Term $term, PriceKind $kind, DateTimeImmutable $at): ?Price
    {
        $match = null;

        foreach ($this->prices[$productId] ?? [] as $price) {
            if ($price->term === null || ! $price->term->equals($term) || $price->kind !== $kind) {
                continue;
            }

            if ($price->isEffectiveAt($at) && ($match === null || $price->effectiveFrom > $match->effectiveFrom)) {
                $match = $price;
            }
        }

        return $match;
    }
}
