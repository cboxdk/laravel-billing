<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Testing;

use Cbox\Billing\Wallet\InMemoryWallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Pool;

/**
 * Wire up a wallet in tests:
 *
 *     $wallet = $this->wallet();
 *     $wallet->grant($grant);
 *     $plan = $wallet->consume('org_a', $calls, 50, $this->consumptionOrder(), now: 1_000);
 *
 * `consumptionOrder()` is the default pool catalog's burn-down order; pass your own
 * ordered pool list to `consume()` when a host defines its own accounts.
 */
trait InteractsWithWallet
{
    private ?InMemoryWallet $wallet = null;

    protected function wallet(): InMemoryWallet
    {
        return $this->wallet ??= new InMemoryWallet;
    }

    /** @return list<Pool> */
    protected function consumptionOrder(): array
    {
        return Pools::defaultConsumptionOrder();
    }
}
