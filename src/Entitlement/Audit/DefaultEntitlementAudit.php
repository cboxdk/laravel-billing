<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit;

use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAudit;
use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAuditSignals;
use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditFinding;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditReport;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;

/**
 * The independent audit for the missing-entitlement outage.
 *
 * For each target it resolves every EXPECTED key through the same
 * {@see MeterPolicyResolver} the enforcer reads, and treats a key as dark when the
 * resolver returns `null` (absent row => deny-by-default) or a disabled policy. Any
 * dark expected key makes the org/plan an outage-class {@see AuditFinding}, and each
 * finding is pushed to {@see EntitlementAuditSignals} as it is discovered.
 *
 * WHY THE ROLLOUT/DRIFT SIGNATURE CANNOT CATCH THIS
 * -------------------------------------------------
 * A drift signature detects change by hashing the entitlement rows and comparing the
 * digest against a previously-stored one (or across replicas). Its universe of inputs
 * is *the rows that exist*. A MISSING row contributes nothing to the digest — there is
 * no entry to hash — so a plan whose rows were never written, or were dropped by a bad
 * rollout/partial backfill, produces a signature that is perfectly self-consistent:
 * it matches itself and matches any replica that is equally empty. The signature is
 * computed from the very rows whose absence is the fault, so absence is invisible to
 * it. The failure mode is silent by construction: deny-by-default turns a missing row
 * into a confident "disabled", and the signature confirms that everything it can see
 * agrees.
 *
 * This audit escapes the trap by comparing against an EXTERNAL oracle — the expected
 * key set drawn from plan/catalog state ({@see ExpectedEntitlements}),
 * derived independently of the rows under test. Because the oracle knows a key *should*
 * resolve, it can observe that the row for it is gone, which no self-referential digest
 * of the rows can. The audit changes nothing about resolution — deny-by-default stays;
 * it only observes and alerts.
 */
readonly class DefaultEntitlementAudit implements EntitlementAudit
{
    public function __construct(
        private MeterPolicyResolver $resolver,
        private EntitlementAuditSignals $signals,
    ) {}

    public function audit(iterable $targets): AuditReport
    {
        $findings = [];
        $count = 0;

        foreach ($targets as $target) {
            $count++;
            $resolved = [];
            $missing = [];

            foreach ($target->expectedKeys as $key) {
                if ($this->isLive($target->org, $key)) {
                    $resolved[] = $key;

                    continue;
                }

                $missing[] = $key;
            }

            if ($missing === []) {
                continue;
            }

            $finding = new AuditFinding($target->org, $target->plan, $target->expectedKeys, $missing, $resolved);
            $findings[] = $finding;
            $this->signals->outage($finding);
        }

        return new AuditReport($findings, $count);
    }

    /**
     * A key is live only when a policy resolves AND is enabled. `null` (the missing-row,
     * deny-by-default case this audit exists to catch) and an explicitly disabled policy
     * both count as dark.
     */
    private function isLive(string $org, string $key): bool
    {
        $policy = $this->resolver->resolve($org, $key);

        return $policy !== null && $policy->enabled;
    }
}
