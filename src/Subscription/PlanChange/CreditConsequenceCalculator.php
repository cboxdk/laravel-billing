<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange;

use Cbox\Billing\Subscription\Enums\CreditGrantMode;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\CreditConsequenceRequest;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\CreditDelta;

/**
 * The pure projection of ADR-0011's plan-switch credit rule + ADR-0012's mid-cycle
 * allotment proration, shared by the previewer so the shown delta is the effect the
 * lifecycle commits.
 *
 * Default (deny-by-default) is **forfeit-and-regrant**: the unspent outgoing recurring
 * allotment is forfeited and the incoming plan's allotment granted — a clean per-cycle
 * reset. A `carryOver` edge instead **keeps** the unspent outgoing allotment (it is
 * carried, not forfeited) while the incoming allotment is still granted.
 *
 * The incoming grant honours the {@see CreditGrantMode}: `FullReset` grants the whole
 * incoming allotment (the money proration already charged the prorated capacity);
 * `Prorated` grants only the remaining days' remainder-safe share
 * ({@see ProratedAllotment}) so a money-equivalent credit is not over-granted on a
 * mid-cycle upgrade. A **deferred downgrade** ({@see forDeferredDowngrade()}) forfeits
 * nothing now — the current allotment runs to period end and the new one lands at
 * renewal.
 *
 * The forfeiture only ever touches the forfeitable allotment, floored at zero, so a
 * negative pay-as-you-go pool is never offset; its surviving debt is reported through
 * as `poolLeftNegative` so the customer sees it persists.
 */
readonly class CreditConsequenceCalculator
{
    /**
     * The credit consequence of a mid-cycle switch. `$mode` selects full-reset vs
     * prorated granting; `$remainingDays` / `$totalDays` describe the anchored cycle
     * and are consulted only for `Prorated` (a zero/degenerate cycle grants the full
     * allotment, matching the whole-period default).
     */
    public function forSwitch(
        CreditConsequenceRequest $request,
        bool $carryOver,
        CreditGrantMode $mode = CreditGrantMode::FullReset,
        int $remainingDays = 0,
        int $totalDays = 0,
    ): CreditDelta {
        $outgoing = max(0, $request->outgoingAllotmentRemaining);

        return new CreditDelta(
            forfeited: $carryOver ? 0 : $outgoing,
            granted: $this->grantedFor($request, $mode, $remainingDays, $totalDays),
            carried: $carryOver ? $outgoing : 0,
            poolLeftNegative: min(0, $request->payAsYouGoBalance),
        );
    }

    /**
     * The credit consequence of a **deferred downgrade** (ADR-0007/0012): the allotment
     * changes at period end, not now. Nothing is forfeited or carried mid-cycle — the
     * current allotment runs its cycle out — and the incoming (lower) allotment is
     * reported as what will be granted at renewal, so the preview is honest about the
     * eventual effect while moving no credits today.
     */
    public function forDeferredDowngrade(CreditConsequenceRequest $request): CreditDelta
    {
        return new CreditDelta(
            forfeited: 0,
            granted: max(0, $request->incomingAllotment),
            carried: 0,
            poolLeftNegative: min(0, $request->payAsYouGoBalance),
        );
    }

    private function grantedFor(
        CreditConsequenceRequest $request,
        CreditGrantMode $mode,
        int $remainingDays,
        int $totalDays,
    ): int {
        $incoming = max(0, $request->incomingAllotment);

        return match ($mode) {
            CreditGrantMode::FullReset => $incoming,
            CreditGrantMode::Prorated => ProratedAllotment::remainingShare($incoming, $remainingDays, $totalDays),
        };
    }
}
