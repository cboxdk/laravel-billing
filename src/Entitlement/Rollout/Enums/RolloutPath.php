<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Enums;

/**
 * Which of the two rollout paths applied a change to an org — the crucial split of a
 * plan-wide entitlement rollout (see the rollout service).
 *
 *  - `Bulk`     — the org had no override, so the plan change was applied verbatim in a
 *                 chunk of many, with NO per-org cache-bust: invalidation is left to the
 *                 hot-path cache TTL. This is the storm-avoiding path for the 100k-org
 *                 tail — one bulk summary, not N bust events.
 *  - `Override` — the org has tailored entitlements, so the change was resolved for it
 *                 individually, written, and its cache busted IMMEDIATELY so the tailored
 *                 result takes effect now rather than waiting out the TTL.
 *
 * The value is also the `via` column of the durable rollout audit row, so an operator can
 * see, per org, which path touched it.
 */
enum RolloutPath: string
{
    case Bulk = 'bulk';
    case Override = 'override';
}
