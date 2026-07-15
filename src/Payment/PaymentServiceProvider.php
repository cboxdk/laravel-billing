<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Gateways\ManualPaymentGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the payment gateway to the dependency-free manual gateway by default.
 * Hosts rebind it to Stripe/Mollie (opt-in adapter packages).
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, static fn (): ManualPaymentGateway => new ManualPaymentGateway);
    }
}
