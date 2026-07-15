<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Enums;

/**
 * How severe a missing-entitlement finding is. Both kinds are outage-class (an org is
 * being refused something a paid plan promised) — this only distinguishes blast radius.
 *
 *  - `AllDisabled`     — EVERY expected key resolved dark. The org is refused everything
 *                        on the plan: a total outage for that org, almost always a bad
 *                        rollout or a wholesale-missing backfill. The loudest severity.
 *  - `MissingExpected` — SOME (not all) expected keys resolved dark. A partial outage:
 *                        one dimension of the plan is silently refused while the rest
 *                        work. Still outage-class, just narrower.
 */
enum EntitlementOutageKind
{
    case AllDisabled;
    case MissingExpected;
}
