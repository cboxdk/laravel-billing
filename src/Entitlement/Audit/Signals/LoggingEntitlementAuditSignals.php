<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Signals;

use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAuditSignals;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditFinding;
use Cbox\Billing\Metering\Signals\LoggingEnforcementSignals;
use Psr\Log\LoggerInterface;

/**
 * The default {@see EntitlementAuditSignals}: emit a PSR-3 line at an OUTAGE severity.
 *
 * This is deliberately louder than {@see LoggingEnforcementSignals},
 * which tops out at `error` for a transient, self-healing infra path. A missing
 * entitlement is not transient and does not self-heal — paying orgs are being refused
 * until someone rewrites the rows — so:
 *
 *  - a TOTAL outage (every expected key dark for the org) logs at `alert`: the org is
 *    refused the whole plan, page someone;
 *  - a PARTIAL outage (some expected keys dark) logs at `critical`.
 *
 * Both sit above the routine `warning`/`error` band, so an operator can alert on this
 * channel distinctly from an ordinary entitlement CHANGE (a grant or revoke), which is
 * expected traffic and is never signalled here.
 */
class LoggingEntitlementAuditSignals implements EntitlementAuditSignals
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function outage(AuditFinding $finding): void
    {
        $context = [
            'org' => $finding->org,
            'plan' => $finding->plan,
            'expected_keys' => $finding->expectedKeys,
            'missing_keys' => $finding->missingKeys,
            'resolved_keys' => $finding->resolvedKeys,
        ];

        if ($finding->isAllDisabled()) {
            $this->logger->alert(
                'Entitlement outage: org resolves to all-disabled for its plan — every expected entitlement is missing, refusing all metered traffic.',
                $context,
            );

            return;
        }

        $this->logger->critical(
            'Entitlement outage: org is missing expected entitlements for its plan — those dimensions are silently refused.',
            $context,
        );
    }
}
