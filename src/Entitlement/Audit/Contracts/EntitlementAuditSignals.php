<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Contracts;

use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditFinding;
use Cbox\Billing\Metering\Contracts\EnforcementSignals;

/**
 * The operator-facing signal channel for the entitlement audit — the sibling of
 * {@see EnforcementSignals}, at a distinctly HIGHER
 * severity. Enforcement signals report a routine, expected-to-happen infra path
 * (fail-open/closed on a transient fault). This channel reports the opposite: a
 * SILENT OUTAGE — orgs on a plan resolving to refused because their entitlement rows
 * are missing. That is never routine; it means paying customers are being turned away
 * and no other check will catch it.
 *
 * The default binding logs at an outage severity; hosts swap in metrics/paging. Only
 * genuine outage findings are signalled — a clean audit is not noise.
 */
interface EntitlementAuditSignals
{
    /**
     * An org/plan is missing expected entitlements and is being silently refused.
     * `$finding->isAllDisabled()` distinguishes a total plan outage from a partial one.
     */
    public function outage(AuditFinding $finding): void;
}
