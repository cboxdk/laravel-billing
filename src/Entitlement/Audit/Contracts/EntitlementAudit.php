<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Contracts;

use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditReport;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditTarget;

/**
 * Detects the missing-entitlement OUTAGE: an org/plan that, because its expected
 * entitlement rows are absent, silently resolves to all-disabled (or is missing an
 * expected key) and is therefore refused what a paid plan promised.
 *
 * The audit only OBSERVES — deny-by-default resolution is unchanged and correct. It
 * compares the host-supplied expected set ({@see AuditTarget::$expectedKeys}) against
 * what the entitlement resolver actually resolves, and reports any gap as an
 * outage-class finding, emitting an outage signal as it goes.
 *
 * Crucially it does NOT consult the rollout/drift signature, which is blind here by
 * construction (see the default implementation's docblock).
 */
interface EntitlementAudit
{
    /**
     * Audit each target and return the outage-class findings. Emitting the per-finding
     * outage signal is the implementation's responsibility.
     *
     * @param  iterable<AuditTarget>  $targets
     */
    public function audit(iterable $targets): AuditReport;
}
