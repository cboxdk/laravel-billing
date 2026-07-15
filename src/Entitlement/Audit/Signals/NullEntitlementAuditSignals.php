<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Signals;

use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAuditSignals;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditFinding;

/**
 * A no-op {@see EntitlementAuditSignals}. The audit still RETURNS its findings in the
 * report; this default simply does not raise them anywhere. A host that wants to be
 * paged on an entitlement outage binds {@see LoggingEntitlementAuditSignals} (or its own
 * metrics/alerting implementation) instead.
 */
class NullEntitlementAuditSignals implements EntitlementAuditSignals
{
    public function outage(AuditFinding $finding): void
    {
        // Intentionally does nothing.
    }
}
