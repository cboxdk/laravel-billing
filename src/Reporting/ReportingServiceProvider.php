<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting;

use Illuminate\Support\ServiceProvider;

/**
 * Binds the reporting calculators (MRR/ARR, churn). Stateless.
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MrrCalculator::class, static fn (): MrrCalculator => new MrrCalculator);
        $this->app->singleton(ChurnCalculator::class, static fn (): ChurnCalculator => new ChurnCalculator);
    }
}
