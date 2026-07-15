<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Entitlement\Audit\Testing\InteractsWithEntitlementAudit;

/**
 * Composition site so the shippable InteractsWithEntitlementAudit trait is type-checked
 * by PHPStan (tests/Fixtures is on the analysis path).
 */
class EntitlementAuditHarness
{
    use InteractsWithEntitlementAudit;
}
