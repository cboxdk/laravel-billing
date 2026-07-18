<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Testing;

use Cbox\Billing\Catalog\Enums\PlanStatus;
use Cbox\Billing\Catalog\InMemoryCatalog;
use Cbox\Billing\Catalog\ValueObjects\PlanRetirement;
use Cbox\Billing\Catalog\ValueObjects\Product;
use DateTimeImmutable;

/**
 * Build catalog plans and an in-memory catalog in tests, with a family and status:
 *
 *     $hosted = $this->plan('hosted-pro', family: 'hosted');
 *     $legacy = $this->plan('old', family: 'hosted', status: PlanStatus::Legacy);
 *     $sunset = $this->retiringPlan('beta', new DateTimeImmutable('2026-06-01'), 'hosted-pro', family: 'hosted');
 *     $catalog = $this->catalogOf($hosted, $legacy, $sunset);
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

    /**
     * A plan being retired at `$retiresAt`, optionally falling to `$defaultSuccessorPlanId`
     * for subscribers who make no choice (ADR-0016).
     */
    protected function retiringPlan(string $id, DateTimeImmutable $retiresAt, ?string $defaultSuccessorPlanId = null, ?string $family = null, ?string $name = null): Product
    {
        return new Product(
            $id,
            $name ?? $id,
            $family,
            PlanStatus::Retiring,
            retirement: new PlanRetirement($retiresAt, $defaultSuccessorPlanId),
        );
    }

    protected function catalogOf(Product ...$plans): InMemoryCatalog
    {
        return new InMemoryCatalog(array_values($plans));
    }
}
