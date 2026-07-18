<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

use DateTimeImmutable;

/**
 * A plan's sunset: a hard cutoff after which the plan is retired and its existing
 * subscribers must resolve off it (ADR-0016).
 *
 *  - `$retiresAt`               — the cutoff instant. From this instant the plan is
 *                                 retired: it is no longer a valid transition target and,
 *                                 at each subscriber's **next renewal on/after this date**,
 *                                 the retirement is enacted.
 *  - `$defaultSuccessorPlanId`  — the plan a subscriber who makes **no choice** (no
 *                                 scheduled successor, no scheduled cancel) falls to at
 *                                 that renewal. When null there is **no** default: a
 *                                 subscriber who does not choose yields an
 *                                 `unresolved-retirement` the host must surface —
 *                                 deny-by-default, never a silent charge on a retired plan.
 *
 * A plan carries this optionally; a plan with no retirement is simply never sunset.
 */
readonly class PlanRetirement
{
    public function __construct(
        public DateTimeImmutable $retiresAt,
        public ?string $defaultSuccessorPlanId = null,
    ) {}

    /** Whether the plan is retired as of `$at` (the cutoff has passed). */
    public function isRetiredAt(DateTimeImmutable $at): bool
    {
        return $at >= $this->retiresAt;
    }

    /** Whether a default successor is configured for subscribers who make no choice. */
    public function hasDefaultSuccessor(): bool
    {
        return $this->defaultSuccessorPlanId !== null;
    }
}
