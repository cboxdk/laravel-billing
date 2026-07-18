<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Retention\Testing\InteractsWithRetention;

/**
 * Composition site so the shippable InteractsWithRetention trait is type-checked by
 * PHPStan (tests/Fixtures is on the analysis path).
 */
class RetentionHarness
{
    use InteractsWithRetention;
}
