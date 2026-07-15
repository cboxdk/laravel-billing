<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the proration calculator and the plan-change previewer.
 */
class SubscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SubscriptionManager::class, static fn (): SubscriptionManager => new SubscriptionManager);

        $this->app->singleton(ProrationCalculator::class, static fn (): ProrationCalculator => new ProrationCalculator);

        $this->app->singleton(PlanChangePreviewer::class, static fn (Application $app): PlanChangePreviewer => new PlanChangePreviewer(
            $app->make(ProrationCalculator::class),
            $app->make(QuoteBuilder::class),
        ));
    }
}
