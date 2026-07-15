<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Entitlement\Rollout\Testing\InteractsWithEntitlementRollout;

/**
 * Composition site so the shippable InteractsWithEntitlementRollout trait is type-checked
 * by PHPStan (tests/Fixtures is on the analysis path).
 */
class EntitlementRolloutHarness
{
    use InteractsWithEntitlementRollout;
}
