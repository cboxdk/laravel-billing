<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Enums;

/**
 * The three-way enforcement result (ADR-0004). Separating a *reached decision* from
 * an *unreachable one* is what lets the failure policy split by cause:
 *
 *  - `Allowed`       — a real decision was reached: the request fits.
 *  - `Denied`        — a real decision was reached: the request is refused on
 *                      SEMANTICS (disabled/unknown meter, unrecognized overage,
 *                      exhausted allowance/quota). Deny-by-default.
 *  - `Indeterminate` — no decision could be reached because a dependency was
 *                      unavailable (store/cache down, lock/lease timeout, transport
 *                      error). Resolved by a configurable infra failure policy, not
 *                      by silently trusting or refusing.
 */
enum OutcomeStatus: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Indeterminate = 'indeterminate';
}
