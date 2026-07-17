<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting;

use Illuminate\Support\ServiceProvider;

/**
 * Binds the reporting calculators (MRR/ARR, churn, MRR movement/waterfall, revenue
 * retention, cohort retention). All stateless and pure.
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MrrCalculator::class, static fn (): MrrCalculator => new MrrCalculator);
        $this->app->singleton(ChurnCalculator::class, static fn (): ChurnCalculator => new ChurnCalculator);
        $this->app->singleton(MrrMovement::class, static fn (): MrrMovement => new MrrMovement);
        $this->app->singleton(RetentionCalculator::class, static fn (): RetentionCalculator => new RetentionCalculator);
        $this->app->singleton(CohortRetention::class, static fn (): CohortRetention => new CohortRetention);
    }
}
