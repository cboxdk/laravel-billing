<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange\ValueObjects;

/**
 * The credit consequence of a plan change, shown in the preview beside the money delta
 * so the confirm step is operationally honest — "you'll lose N included credits; your M
 * purchased credits carry over" (ADR-0011). All counts are in the allotment's units.
 *
 *  - `forfeited`        — outgoing recurring allotment zeroed by the switch (0 when the
 *                         edge carries over).
 *  - `granted`          — incoming plan's recurring allotment granted by the switch.
 *  - `carried`          — outgoing recurring allotment kept instead of forfeited
 *                         (non-zero only along a `carryOver` edge).
 *  - `poolLeftNegative` — a `mayGoNegative` pay-as-you-go pool's remaining debt after
 *                         the switch (`<= 0`); never offset by the allotment, reported
 *                         so the customer sees it survives the change.
 */
readonly class CreditDelta
{
    public function __construct(
        public int $forfeited = 0,
        public int $granted = 0,
        public int $carried = 0,
        public int $poolLeftNegative = 0,
    ) {}

    /** The net change to spendable allotment: what is granted or carried, less what is forfeited. */
    public function net(): int
    {
        return $this->granted + $this->carried - $this->forfeited;
    }
}
