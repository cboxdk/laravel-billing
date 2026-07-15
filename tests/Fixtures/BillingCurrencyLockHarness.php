<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Account\Testing\InteractsWithBillingCurrencyLock;

/**
 * Composition site so the shippable InteractsWithBillingCurrencyLock trait is
 * type-checked by PHPStan (tests/Fixtures is on the analysis path).
 */
class BillingCurrencyLockHarness
{
    use InteractsWithBillingCurrencyLock;
}
