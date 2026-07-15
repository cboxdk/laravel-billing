<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Testing;

use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAudit;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditReport;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditTarget;

/**
 * A programmable {@see EntitlementAudit} for testing code that DRIVES the audit (a
 * command, a scheduled job, a rollout gate) without exercising real resolution. It
 * records the targets it was handed and returns a preset {@see AuditReport} — clean by
 * default, or whatever {@see willReport()} was given.
 */
class FakeEntitlementAudit implements EntitlementAudit
{
    /** @var list<AuditTarget> */
    private array $audited = [];

    private ?AuditReport $report = null;

    /** Preset the report this fake returns on the next {@see audit()} call. */
    public function willReport(AuditReport $report): self
    {
        $this->report = $report;

        return $this;
    }

    public function audit(iterable $targets): AuditReport
    {
        $seen = [];
        foreach ($targets as $target) {
            $seen[] = $target;
        }
        $this->audited = $seen;

        return $this->report ?? new AuditReport([], count($seen));
    }

    /** @return list<AuditTarget> the targets passed to the last {@see audit()} call. */
    public function auditedTargets(): array
    {
        return $this->audited;
    }
}
