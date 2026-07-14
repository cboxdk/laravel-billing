<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Metering\Testing\InteractsWithMetering;

/**
 * Composition site so the shippable InteractsWithMetering trait is type-checked
 * by PHPStan (tests/Fixtures is on the analysis path).
 */
class MeteringHarness
{
    use InteractsWithMetering;
}
