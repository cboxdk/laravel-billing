<?php

declare(strict_types=1);

namespace Cbox\Billing\Account;

use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\CurrencyLock\DatabaseBillingCurrencyLock;
use Cbox\Billing\Account\CurrencyLock\InMemoryBillingCurrencyLock;
use Cbox\Billing\Account\Standing\InMemoryAccountStanding;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the billing-account invariants: the currency lock and the standing store.
 * The currency lock defaults to the zero-config in-memory store and swaps to the
 * durable one by config (`billing.account.currency_lock_store = database`), which
 * pairs with the invoice number sequence on the same connection so the first-finalize
 * stamp and the invoice commit together. Account standing defaults to the in-memory
 * store; hosts rebind it to a durable one.
 */
class AccountServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BillingCurrencyLock::class, static function (Application $app): BillingCurrencyLock {
            $driver = $app->make(Config::class)->get('billing.account.currency_lock_store', 'memory');

            if ($driver === 'database') {
                return new DatabaseBillingCurrencyLock($app->make('db')->connection());
            }

            return new InMemoryBillingCurrencyLock;
        });

        $this->app->singleton(AccountStanding::class, static fn (): InMemoryAccountStanding => new InMemoryAccountStanding);
    }
}
