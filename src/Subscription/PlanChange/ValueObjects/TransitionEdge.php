<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange\ValueObjects;

/**
 * An explicitly declared, directed transition path between two plan families
 * (ADR-0010). Its existence is what lets a subscription cross from one family to
 * another; without a matching edge a cross-family move is refused (deny-by-default).
 *
 *  - `guidance`  — optional caller-facing note for the allowed move (e.g.
 *                  "requires migration / contact sales"), surfaced on the decision.
 *  - `carryOver` — when true, a switch along this edge **keeps** the unspent outgoing
 *                  recurring allotment instead of the default forfeit-and-regrant
 *                  reset (ADR-0011). Opt-in per edge; the default is forfeit-and-regrant.
 *
 * An edge may also be declared within a single family (`fromFamily === toFamily`)
 * purely to attach `guidance`/`carryOver` to same-family moves, which are otherwise
 * allowed with the reset default.
 */
readonly class TransitionEdge
{
    public function __construct(
        public string $fromFamily,
        public string $toFamily,
        public ?string $guidance = null,
        public bool $carryOver = false,
    ) {}

    /** Does this edge describe a move from `$from` to `$to`? */
    public function connects(string $from, string $to): bool
    {
        return $this->fromFamily === $from && $this->toFamily === $to;
    }
}
