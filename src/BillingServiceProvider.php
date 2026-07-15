<?php

declare(strict_types=1);

namespace Cbox\Billing;

use Cbox\Billing\Catalog\CatalogServiceProvider;
use Cbox\Billing\Metering\MeteringServiceProvider;
use Cbox\Billing\Quote\QuoteServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * The package entry point. Merges config and registers each domain module's
 * provider. Modules depend on kernels/contracts, never on each other's concretes.
 */
class BillingServiceProvider extends ServiceProvider
{
    /**
     * @var list<class-string<ServiceProvider>>
     */
    private const MODULE_PROVIDERS = [
        MeteringServiceProvider::class,
        CatalogServiceProvider::class,
        QuoteServiceProvider::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing.php', 'billing');

        foreach (self::MODULE_PROVIDERS as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/billing.php' => $this->app->configPath('billing.php'),
            ], 'billing-config');
        }
    }
}
