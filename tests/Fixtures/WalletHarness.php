<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Wallet\Testing\InteractsWithWallet;

/**
 * Composition site so the shippable InteractsWithWallet trait is type-checked by
 * PHPStan (tests/Fixtures is on the analysis path).
 */
class WalletHarness
{
    use InteractsWithWallet;
}
