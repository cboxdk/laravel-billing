<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\ValueObjects;

use Cbox\Billing\Entitlement\Audit\Enums\EntitlementOutageKind;

/**
 * The result of one audit pass: the outage-class findings, plus how many org/plan
 * targets were examined to produce them. A pass with no findings ({@see isClean()})
 * means every expected key resolved live for every target — the healthy state.
 *
 * The report is the observed record; it never mutates entitlement resolution. Immutable.
 */
readonly class AuditReport
{
    /**
     * @param  list<AuditFinding>  $findings
     */
    public function __construct(
        public array $findings,
        public int $targetsAudited,
    ) {}

    /** No org/plan is missing an expected entitlement. */
    public function isClean(): bool
    {
        return $this->findings === [];
    }

    /** At least one org/plan is refused an expected entitlement. */
    public function hasOutage(): bool
    {
        return $this->findings !== [];
    }

    /**
     * The findings where the org is refused the WHOLE plan (every expected key dark) —
     * the highest-blast-radius subset, worth paging on first.
     *
     * @return list<AuditFinding>
     */
    public function totalOutages(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (AuditFinding $f): bool => $f->kind() === EntitlementOutageKind::AllDisabled,
        ));
    }
}
