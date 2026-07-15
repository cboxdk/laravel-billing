<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Catalog\Testing\InteractsWithCatalog;

/**
 * Composition site so the shippable InteractsWithCatalog trait is type-checked by
 * PHPStan (tests/Fixtures is on the analysis path).
 */
class CatalogHarness
{
    use InteractsWithCatalog;
}
