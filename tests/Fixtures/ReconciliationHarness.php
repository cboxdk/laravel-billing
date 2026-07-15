<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Reconciliation\Testing\InteractsWithReconciliation;

/**
 * Composition site so the shippable InteractsWithReconciliation trait is type-checked
 * by PHPStan (tests/Fixtures is on the analysis path).
 */
class ReconciliationHarness
{
    use InteractsWithReconciliation;
}
