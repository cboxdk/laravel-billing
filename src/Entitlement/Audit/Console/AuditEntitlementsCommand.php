<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Console;

use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAudit;
use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditFinding;
use Illuminate\Console\Command;

/**
 * Thin console entry point over {@see EntitlementAudit}. It pulls the expected org/plan
 * targets from the host-wired {@see ExpectedEntitlements} oracle, runs the audit, renders
 * the outage findings, and exits non-zero when any outage is found — so a rollout gate or
 * scheduler can react to the exit code while the audit's signal channel alerts operators.
 *
 * All the detection logic lives in the audit contract; this adapter only marshals input
 * and output.
 */
class AuditEntitlementsCommand extends Command
{
    protected $signature = 'billing:entitlements:audit';

    protected $description = 'Audit expected plan entitlements against what resolves, flagging missing-row outages.';

    public function handle(ExpectedEntitlements $expected, EntitlementAudit $audit): int
    {
        $report = $audit->audit($expected->targets());

        if ($report->isClean()) {
            $this->info(sprintf('Entitlement audit clean: %d target(s), no missing entitlements.', $report->targetsAudited));

            return self::SUCCESS;
        }

        foreach ($report->findings as $finding) {
            $this->renderFinding($finding);
        }

        $this->error(sprintf(
            'Entitlement outage: %d of %d target(s) missing expected entitlements (%d fully disabled).',
            count($report->findings),
            $report->targetsAudited,
            count($report->totalOutages()),
        ));

        return self::FAILURE;
    }

    private function renderFinding(AuditFinding $finding): void
    {
        $label = $finding->isAllDisabled() ? 'ALL-DISABLED' : 'MISSING';

        $this->line(sprintf(
            '<error>%s</error> %s/%s missing [%s] (resolved [%s])',
            $label,
            $finding->org,
            $finding->plan,
            implode(', ', $finding->missingKeys),
            implode(', ', $finding->resolvedKeys),
        ));
    }
}
