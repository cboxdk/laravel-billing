<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\ValueObjects;

use Cbox\Billing\Entitlement\Audit\DefaultEntitlementAudit;

/**
 * One org/plan the audit will check, carrying the plan's EXPECTED entitlement keys.
 *
 * The whole integrity of the audit hinges on where `expectedKeys` comes from: it is the
 * set the plan/catalog state SAYS this org should resolve — supplied by the host from
 * plan definition, not read back out of the entitlement rows the audit is judging. That
 * independence is the point (see {@see DefaultEntitlementAudit}):
 * only an oracle derived separately from the resolved rows can notice that the rows are
 * missing.
 *
 * `expectedKeys` are the `(org, meter)` dimension keys the plan grants — the same keys
 * the metering enforcer resolves policy for. Immutable.
 */
readonly class AuditTarget
{
    /**
     * @param  list<string>  $expectedKeys  the meter keys this plan is expected to grant
     */
    public function __construct(
        public string $org,
        public string $plan,
        public array $expectedKeys,
    ) {}
}
