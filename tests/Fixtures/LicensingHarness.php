<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Licensing\Testing\InteractsWithLicensing;

/**
 * Composition site so the shippable licensing testing trait is type-checked by
 * PHPStan (tests/Fixtures is on the analysis path).
 */
class LicensingHarness
{
    use InteractsWithLicensing;
}
