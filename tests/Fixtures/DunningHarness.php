<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Payment\Dunning\Testing\InteractsWithDunning;

/**
 * Composition site so the shippable dunning testing trait is type-checked by PHPStan
 * (tests/Fixtures is on the analysis path).
 */
class DunningHarness
{
    use InteractsWithDunning;
}
