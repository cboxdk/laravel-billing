<?php

declare(strict_types=1);

namespace Cbox\Billing;

use Cbox\Billing\Account\AccountServiceProvider;
use Cbox\Billing\Catalog\CatalogServiceProvider;
use Cbox\Billing\Entitlement\EntitlementServiceProvider;
use Cbox\Billing\Invoice\InvoiceServiceProvider;
use Cbox\Billing\Ledger\LedgerServiceProvider;
use Cbox\Billing\Licensing\LicensingServiceProvider;
use Cbox\Billing\Metering\MeteringServiceProvider;
use Cbox\Billing\Payment\PaymentServiceProvider;
use Cbox\Billing\Pricing\PricingServiceProvider;
use Cbox\Billing\Quote\QuoteServiceProvider;
use Cbox\Billing\Reconciliation\ReconciliationServiceProvider;
use Cbox\Billing\Refund\RefundServiceProvider;
use Cbox\Billing\Reporting\ReportingServiceProvider;
use Cbox\Billing\Subscription\SubscriptionServiceProvider;
use Cbox\Billing\Wallet\WalletServiceProvider;
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
        WalletServiceProvider::class,
        MeteringServiceProvider::class,
        CatalogServiceProvider::class,
        QuoteServiceProvider::class,
        AccountServiceProvider::class,
        InvoiceServiceProvider::class,
        SubscriptionServiceProvider::class,
        PaymentServiceProvider::class,
        RefundServiceProvider::class,
        PricingServiceProvider::class,
        EntitlementServiceProvider::class,
        ReportingServiceProvider::class,
        LedgerServiceProvider::class,
        ReconciliationServiceProvider::class,
        LicensingServiceProvider::class,
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
