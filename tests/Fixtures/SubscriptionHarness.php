<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Subscription\Testing\InteractsWithSubscriptionLifecycle;

/**
 * Composition site so the shippable InteractsWithSubscriptionLifecycle trait is
 * type-checked by PHPStan (tests/Fixtures is on the analysis path).
 */
class SubscriptionHarness
{
    use InteractsWithSubscriptionLifecycle;
}
