<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Contracts;

use Cbox\Billing\Entitlement\Audit\DefaultEntitlementAudit;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditTarget;

/**
 * The INDEPENDENT oracle for what each org/plan is supposed to resolve — the source of
 * the audit's expected set. It MUST be derived from plan/catalog definition (the plan a
 * subscription is on, and the meter keys that plan grants), NOT from the entitlement
 * rows the audit then inspects. If the expected set were read back out of the same rows,
 * the audit would compare the rows to themselves and be as blind to a missing row as the
 * rollout/drift signature is (see {@see DefaultEntitlementAudit}).
 *
 * The host implements this over its plan catalog. Deny-by-default: the shipped binding
 * yields nothing, so the audit has nothing to check until the host wires a real source.
 */
interface ExpectedEntitlements
{
    /**
     * Every org/plan to audit, each carrying the keys its plan is expected to grant.
     *
     * @return iterable<AuditTarget>
     */
    public function targets(): iterable;
}
