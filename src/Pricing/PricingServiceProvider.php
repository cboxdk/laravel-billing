<?php

declare(strict_types=1);

namespace Cbox\Billing\Pricing;

use Illuminate\Support\ServiceProvider;

/**
 * Binds the pricing-ops services (coupon application). Stateless — resolvable from
 * the container for DI consistency.
 */
class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CouponApplier::class, static fn (): CouponApplier => new CouponApplier);
    }
}
