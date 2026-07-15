<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange;

use Cbox\Billing\Subscription\PlanChange\ValueObjects\CreditConsequenceRequest;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\CreditDelta;

/**
 * The pure projection of ADR-0011's plan-switch credit rule, shared by the previewer so
 * the shown delta is the effect the lifecycle commits.
 *
 * Default (deny-by-default) is **forfeit-and-regrant**: the unspent outgoing recurring
 * allotment is forfeited and the incoming plan's allotment granted — a clean per-cycle
 * reset. A `carryOver` edge instead **keeps** the unspent outgoing allotment (it is
 * carried, not forfeited) while the incoming allotment is still granted.
 *
 * The forfeiture only ever touches the forfeitable allotment, floored at zero, so a
 * negative pay-as-you-go pool is never offset; its surviving debt is reported through
 * as `poolLeftNegative` so the customer sees it persists.
 */
readonly class CreditConsequenceCalculator
{
    public function forSwitch(CreditConsequenceRequest $request, bool $carryOver): CreditDelta
    {
        $outgoing = max(0, $request->outgoingAllotmentRemaining);

        return new CreditDelta(
            forfeited: $carryOver ? 0 : $outgoing,
            granted: max(0, $request->incomingAllotment),
            carried: $carryOver ? $outgoing : 0,
            poolLeftNegative: min(0, $request->payAsYouGoBalance),
        );
    }
}
