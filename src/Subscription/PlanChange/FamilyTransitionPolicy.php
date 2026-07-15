<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange;

use Cbox\Billing\Catalog\Contracts\Catalog;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\TransitionDecision;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\TransitionEdge;

/**
 * The batteries-included {@see TransitionPolicy}, a family graph, **deny-by-default**
 * on cross-family moves (ADR-0010):
 *
 *  - **A legacy target is always refused** — a legacy plan has no inbound edge, so a
 *    subscription can never switch (back) to it, even within its own family.
 *  - **Same family → allowed.** An in-family move needs no edge; it resets credit by
 *    default, unless a same-family edge opts into `carryOver`/guidance.
 *  - **Across families → allowed only along an explicitly declared {@see TransitionEdge}.**
 *    The edge carries optional guidance and the per-edge `carryOver` flag.
 *  - **Everything else → refused**, with a reason the caller surfaces.
 */
readonly class FamilyTransitionPolicy implements TransitionPolicy
{
    /** @var list<TransitionEdge> */
    private array $edges;

    public function __construct(TransitionEdge ...$edges)
    {
        $this->edges = array_values($edges);
    }

    public function canTransition(Product $from, Product $to): TransitionDecision
    {
        // A legacy plan is a valid source but never a target: no inbound edge, ever.
        if ($to->isLegacy()) {
            return TransitionDecision::disallowed(
                "Plan [{$to->id}] is legacy and cannot be switched to.",
            );
        }

        $edge = $this->edgeBetween($from->family(), $to->family());

        // Same family is allowed with no edge; an optional same-family edge only tunes
        // guidance/carryOver on top of the default reset.
        if ($from->sameFamilyAs($to)) {
            return TransitionDecision::allowed(
                guidance: $edge?->guidance,
                carryOver: $edge !== null && $edge->carryOver,
            );
        }

        // Across families: only along a declared edge.
        if ($edge !== null) {
            return TransitionDecision::allowed(guidance: $edge->guidance, carryOver: $edge->carryOver);
        }

        return TransitionDecision::disallowed(
            "No transition path from family [{$from->family()}] to family [{$to->family()}].",
        );
    }

    public function availableTransitions(Product $from, Catalog $catalog): array
    {
        $targets = [];

        foreach ($catalog->products() as $candidate) {
            if ($candidate->id === $from->id) {
                continue;
            }

            if ($this->canTransition($from, $candidate)->isAllowed()) {
                $targets[] = $candidate;
            }
        }

        return $targets;
    }

    private function edgeBetween(string $from, string $to): ?TransitionEdge
    {
        foreach ($this->edges as $edge) {
            if ($edge->connects($from, $to)) {
                return $edge;
            }
        }

        return null;
    }
}
