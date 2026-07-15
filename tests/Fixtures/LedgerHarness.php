<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Ledger\Testing\InteractsWithLedger;

/**
 * Composition site so the shippable InteractsWithLedger trait is type-checked by
 * PHPStan (tests/Fixtures is on the analysis path).
 */
class LedgerHarness
{
    use InteractsWithLedger;
}
