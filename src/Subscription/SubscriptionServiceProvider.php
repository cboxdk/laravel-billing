<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Subscription\Contracts\ForfeitureHandler;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the proration calculator, the plan-change previewer, and the forfeiture
 * handler + lifecycle seam that drives forfeiture off subscription transitions.
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

        $this->app->singleton(ForfeitureHandler::class, static fn (Application $app): WalletForfeiture => new WalletForfeiture(
            $app->make(Wallet::class),
        ));

        $this->app->singleton(SubscriptionLifecycle::class, static fn (Application $app): SubscriptionLifecycle => new SubscriptionLifecycle(
            $app->make(SubscriptionManager::class),
            $app->make(ForfeitureHandler::class),
        ));
    }
}
