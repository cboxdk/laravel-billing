<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange\ValueObjects;

/**
 * The wallet-derived inputs the previewer needs to project a plan switch's credit
 * consequence (ADR-0011), kept explicit so the previewer stays a pure function of its
 * inputs (preview equals the committed effect by construction). The caller reads these
 * from the wallet and passes them in:
 *
 *  - `outgoingAllotmentRemaining` — the unspent recurring allotment of the outgoing
 *    plan (the forfeitable `included`-pool balance), `>= 0`.
 *  - `incomingAllotment`          — the recurring allotment the incoming plan grants, `>= 0`.
 *  - `payAsYouGoBalance`          — the current balance of the `mayGoNegative` pool; a
 *    negative value is reported through untouched (never offset by the allotment).
 */
readonly class CreditConsequenceRequest
{
    public function __construct(
        public int $outgoingAllotmentRemaining = 0,
        public int $incomingAllotment = 0,
        public int $payAsYouGoBalance = 0,
    ) {}
}
