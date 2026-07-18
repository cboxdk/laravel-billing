<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests;

use Cbox\Billing\Account\Testing\InteractsWithAccountStanding;
use Cbox\Billing\Account\Testing\InteractsWithBillingCurrencyLock;
use Cbox\Billing\BillingServiceProvider;
use Cbox\Billing\Catalog\Testing\InteractsWithCatalog;
use Cbox\Billing\Entitlement\Audit\Testing\InteractsWithEntitlementAudit;
use Cbox\Billing\Entitlement\Rollout\Testing\InteractsWithEntitlementRollout;
use Cbox\Billing\Ledger\Testing\InteractsWithLedger;
use Cbox\Billing\Licensing\Testing\InteractsWithLicensing;
use Cbox\Billing\Metering\Testing\InteractsWithMetering;
use Cbox\Billing\Payment\Dunning\Testing\InteractsWithDunning;
use Cbox\Billing\Payment\Testing\InteractsWithWebhooks;
use Cbox\Billing\Reconciliation\Testing\InteractsWithReconciliation;
use Cbox\Billing\Refund\Testing\InteractsWithRefunds;
use Cbox\Billing\Retention\Testing\InteractsWithRetention;
use Cbox\Billing\Subscription\Testing\InteractsWithSubscriptionLifecycle;
use Cbox\Billing\Wallet\Testing\InteractsWithWallet;
use Cbox\Geo\GeoServiceProvider;
use Cbox\Tax\TaxServiceProvider;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithAccountStanding;
    use InteractsWithBillingCurrencyLock;
    use InteractsWithCatalog;
    use InteractsWithDunning;
    use InteractsWithEntitlementAudit;
    use InteractsWithEntitlementRollout;
    use InteractsWithLedger;
    use InteractsWithLicensing;
    use InteractsWithMetering;
    use InteractsWithReconciliation;
    use InteractsWithRefunds;
    use InteractsWithRetention;
    use InteractsWithSubscriptionLifecycle;
    use InteractsWithWallet;
    use InteractsWithWebhooks;

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [GeoServiceProvider::class, TaxServiceProvider::class, BillingServiceProvider::class];
    }
}
