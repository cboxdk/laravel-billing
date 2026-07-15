<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Testing;

use Cbox\Billing\Wallet\CreditConsumer;
use Cbox\Billing\Wallet\DatabaseWallet;
use Cbox\Billing\Wallet\InMemoryWallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Illuminate\Database\ConnectionInterface;

/**
 * Wire up a wallet in tests:
 *
 *     $wallet = $this->wallet();
 *     $wallet->grant($grant);
 *     $plan = $wallet->consume('org_a', $calls, 50, $this->consumptionOrder(), now: 1_000);
 *
 * For the durable wallet, hand `databaseWallet()` a connection — construct a fresh
 * instance whenever you want to prove state was persisted rather than held in memory:
 *
 *     $wallet = $this->databaseWallet($connection);
 *     $wallet->grant($grant);
 *     expect($this->databaseWallet($connection)->balance(...))->toBe(...); // reads it back
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

    /**
     * A durable wallet on `$connection`. Deliberately NOT memoized: each call is a fresh
     * instance, so re-calling proves a balance came from the store, not in-process state.
     */
    protected function databaseWallet(ConnectionInterface $connection): DatabaseWallet
    {
        return new DatabaseWallet($connection, new CreditConsumer);
    }

    /** @return list<Pool> */
    protected function consumptionOrder(): array
    {
        return Pools::defaultConsumptionOrder();
    }
}
