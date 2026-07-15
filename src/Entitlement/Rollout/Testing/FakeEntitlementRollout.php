<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Testing;

use Cbox\Billing\Entitlement\Rollout\Contracts\EntitlementRollout;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\PlanEntitlementChange;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutReport;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutTarget;

/**
 * A programmable {@see EntitlementRollout} for testing code that DRIVES a rollout (a
 * command, a scheduled job, a release gate) without exercising the real journal or cache.
 * It records the change and cohort it was handed and returns a preset {@see RolloutReport}
 * — or, by default, one it derives from the cohort split (bulk vs override) so the caller
 * still sees a truthful shape.
 */
class FakeEntitlementRollout implements EntitlementRollout
{
    /** @var list<array{change: PlanEntitlementChange, cohort: list<RolloutTarget>}> */
    private array $applied = [];

    private ?RolloutReport $report = null;

    /** Preset the report this fake returns on the next {@see apply()} call. */
    public function willReport(RolloutReport $report): self
    {
        $this->report = $report;

        return $this;
    }

    public function apply(PlanEntitlementChange $change, iterable $cohort): RolloutReport
    {
        $targets = [];
        foreach ($cohort as $target) {
            $targets[] = $target;
        }

        $this->applied[] = ['change' => $change, 'cohort' => $targets];

        if ($this->report !== null) {
            return $this->report;
        }

        $overrideOrgs = count(array_filter($targets, static fn (RolloutTarget $t): bool => $t->hasOverride()));
        $bulkOrgs = count($targets) - $overrideOrgs;

        return new RolloutReport(
            $change->id,
            $change->plan,
            $bulkOrgs,
            $overrideOrgs,
            $bulkOrgs > 0 ? 1 : 0,
            $overrideOrgs,
        );
    }

    /** The change passed to the last {@see apply()} call, or null if never called. */
    public function lastChange(): ?PlanEntitlementChange
    {
        $last = end($this->applied);

        return $last === false ? null : $last['change'];
    }

    /** @return list<RolloutTarget> the cohort passed to the last {@see apply()} call. */
    public function lastCohort(): array
    {
        $last = end($this->applied);

        return $last === false ? [] : $last['cohort'];
    }

    public function applyCount(): int
    {
        return count($this->applied);
    }
}
