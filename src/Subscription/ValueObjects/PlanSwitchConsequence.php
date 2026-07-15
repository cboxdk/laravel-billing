<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Subscription\Contracts\ForfeitureHandler;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;

/**
 * The wallet instructions for a plan switch's credit consequence (ADR-0011), handed to
 * the {@see ForfeitureHandler} by the lifecycle:
 *
 *  - `carryOver`         — when false (the default), the outgoing forfeitable allotment
 *    is forfeited; when true (a `carryOver` edge), it is kept.
 *  - `incomingAllotment` — the incoming plan's recurring allotment to grant on the
 *    switch, already built for the moving org, or null when the incoming plan grants none.
 *
 * The incoming allotment is granted in **both** cases: forfeit-and-regrant resets to it,
 * carry-over adds it on top of the kept outgoing balance.
 */
readonly class PlanSwitchConsequence
{
    public function __construct(
        public bool $carryOver = false,
        public ?CreditGrant $incomingAllotment = null,
    ) {}
}
