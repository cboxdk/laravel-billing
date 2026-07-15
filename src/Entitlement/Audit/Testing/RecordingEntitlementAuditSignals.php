<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Testing;

use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAuditSignals;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditFinding;

/**
 * In-memory {@see EntitlementAuditSignals} for tests: records every outage finding the
 * audit raises so a test can assert the outage signal actually fired (and inspect the
 * org/plan/missing-keys), instead of only checking the returned report.
 */
class RecordingEntitlementAuditSignals implements EntitlementAuditSignals
{
    /** @var list<AuditFinding> */
    private array $outages = [];

    public function outage(AuditFinding $finding): void
    {
        $this->outages[] = $finding;
    }

    /** @return list<AuditFinding> every outage signalled, in order. */
    public function outageSignals(): array
    {
        return $this->outages;
    }

    /** How many outage signals were emitted. */
    public function outageCount(): int
    {
        return count($this->outages);
    }

    /** How many of the signalled outages were total (org refused the whole plan). */
    public function totalOutageCount(): int
    {
        return count(array_filter($this->outages, static fn (AuditFinding $f): bool => $f->isAllDisabled()));
    }
}
