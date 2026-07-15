<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Testing;

use Cbox\Billing\Catalog\Enums\PlanStatus;
use Cbox\Billing\Catalog\InMemoryCatalog;
use Cbox\Billing\Catalog\ValueObjects\Product;

/**
 * Build catalog plans and an in-memory catalog in tests, with a family and status:
 *
 *     $hosted = $this->plan('hosted-pro', family: 'hosted');
 *     $legacy = $this->plan('old', family: 'hosted', status: PlanStatus::Legacy);
 *     $catalog = $this->catalogOf($hosted, $legacy);
 *
 * A plan without a declared family is its own singleton family (its id), so it is
 * deny-by-default against cross-family moves.
 */
trait InteractsWithCatalog
{
    protected function plan(string $id, ?string $family = null, PlanStatus $status = PlanStatus::Offered, ?string $name = null): Product
    {
        return new Product($id, $name ?? $id, $family, $status);
    }

    protected function legacyPlan(string $id, ?string $family = null): Product
    {
        return $this->plan($id, $family, PlanStatus::Legacy);
    }

    protected function catalogOf(Product ...$plans): InMemoryCatalog
    {
        return new InMemoryCatalog(array_values($plans));
    }
}
