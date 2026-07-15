<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet;

use Cbox\Billing\Wallet\Contracts\Wallet;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the {@see Wallet} contract to the in-memory reference implementation as an
 * overridable default. A host swaps in its durable (Eloquent) wallet by re-binding
 * {@see Wallet::class}; everything downstream — the burn-down, expiry, and
 * forfeiture — depends on the contract, never on this concrete.
 */
class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Wallet::class, static fn (): InMemoryWallet => new InMemoryWallet);
    }
}
