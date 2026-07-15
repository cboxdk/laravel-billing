<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger;

use Cbox\Billing\Ledger\Contracts\Ledger;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the ledger to the in-memory default and loads the durable-ledger migration.
 * Hosts that want persistence rebind {@see Ledger} to {@see DatabaseLedger} and run
 * the migration.
 */
class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Ledger::class, static fn (): InMemoryLedger => new InMemoryLedger);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../database/migrations' => $this->app->databasePath('migrations'),
            ], 'billing-migrations');
        }
    }
}
