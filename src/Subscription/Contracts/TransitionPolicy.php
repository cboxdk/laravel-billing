<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Contracts;

use Cbox\Billing\Catalog\Contracts\Catalog;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Subscription\PlanChange\FamilyTransitionPolicy;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\TransitionDecision;

/**
 * Decides whether a subscription may move from one plan to another, so the plan-change
 * flow can **refuse** a nonsensical or unsupported target rather than silently prorate
 * it (ADR-0010). The invariant lives here, with the plan-change logic, so every
 * consumer (API, SDK, jobs) is protected, not just an app UI.
 *
 * Bound contracts-first to the {@see FamilyTransitionPolicy} default, so a host can
 * decorate or replace it and tests can substitute a fake.
 */
interface TransitionPolicy
{
    /** Allowed, or Disallowed with a caller-facing reason. */
    public function canTransition(Product $from, Product $to): TransitionDecision;

    /**
     * The plans in `$catalog` the subscription on `$from` may switch to — every plan
     * for which {@see canTransition()} is allowed — so UIs and upgrade gates only ever
     * offer valid targets.
     *
     * @return list<Product>
     */
    public function availableTransitions(Product $from, Catalog $catalog): array;
}
