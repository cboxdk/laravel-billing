<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests;

use Cbox\Billing\BillingServiceProvider;
use Cbox\Billing\Metering\Testing\InteractsWithMetering;
use Cbox\Geo\GeoServiceProvider;
use Cbox\Tax\TaxServiceProvider;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithMetering;

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [GeoServiceProvider::class, TaxServiceProvider::class, BillingServiceProvider::class];
    }
}
