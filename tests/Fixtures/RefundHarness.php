<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Account\Testing\InteractsWithAccountStanding;
use Cbox\Billing\Refund\Testing\InteractsWithRefunds;

/**
 * Composition site so the shippable refund/standing testing traits are type-checked by
 * PHPStan (tests/Fixtures is on the analysis path).
 */
class RefundHarness
{
    use InteractsWithAccountStanding;
    use InteractsWithRefunds;
}
