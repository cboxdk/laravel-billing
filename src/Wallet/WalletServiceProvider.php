<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet;

use Cbox\Billing\Wallet\Contracts\Wallet;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the {@see Wallet} contract. The zero-config default is the in-memory reference
 * wallet; setting `billing.wallet.store = database` swaps in the durable
 * {@see DatabaseWallet} (run the migration), whose grant lots survive a restart — the
 * same wiring the ledger, event log, and currency lock use for their durable stores.
 * Everything downstream — the burn-down, expiry, and forfeiture — depends on the
 * contract, never on either concrete, so a host may also rebind {@see Wallet::class} to
 * its own implementation. The migration is loaded/published by the package as a whole.
 */
class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Wallet::class, static function (Application $app): Wallet {
            $driver = $app->make(Config::class)->get('billing.wallet.store', 'memory');

            if ($driver === 'database') {
                return new DatabaseWallet($app->make('db')->connection());
            }

            return new InMemoryWallet;
        });
    }
}
