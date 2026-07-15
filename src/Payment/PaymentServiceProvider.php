<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment;

use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Contracts\ProcessedEventStore;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Dunning\Contracts\DelinquencyPolicy;
use Cbox\Billing\Payment\Dunning\Contracts\DelinquentAllowList;
use Cbox\Billing\Payment\Dunning\Contracts\DunningStateStore;
use Cbox\Billing\Payment\Dunning\DefaultDelinquencyPolicy;
use Cbox\Billing\Payment\Dunning\DunningRunner;
use Cbox\Billing\Payment\Dunning\Storage\InMemoryDelinquentAllowList;
use Cbox\Billing\Payment\Dunning\Storage\InMemoryDunningStateStore;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningConfig;
use Cbox\Billing\Payment\Gateways\ManualPaymentGateway;
use Cbox\Billing\Payment\Webhook\DefaultWebhookIngest;
use Cbox\Billing\Payment\Webhook\Storage\InMemoryInvoicePaymentApplier;
use Cbox\Billing\Payment\Webhook\Storage\InMemoryProcessedEventStore;
use Cbox\Billing\Payment\Webhook\Storage\InMemorySettledPaymentStore;
use Cbox\Billing\Payment\Webhook\Verifiers\DenyingWebhookVerifier;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the payment gateway to the dependency-free manual gateway by default (hosts
 * rebind it to an SDK-backed adapter package), plus the dunning/delinquency policy and
 * its runner. The delinquency decision is a pure, swappable {@see DelinquencyPolicy};
 * the notice-progress store and the per-account bypass allow-list default to the
 * zero-config in-memory stores; the knob set is read from `billing.payment.dunning`.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, static fn (): ManualPaymentGateway => new ManualPaymentGateway);

        $this->app->singleton(DelinquencyPolicy::class, static fn (): DefaultDelinquencyPolicy => new DefaultDelinquencyPolicy);

        $this->app->singleton(DunningStateStore::class, static fn (): InMemoryDunningStateStore => new InMemoryDunningStateStore);

        $this->app->singleton(DelinquentAllowList::class, static fn (): InMemoryDelinquentAllowList => new InMemoryDelinquentAllowList);

        $this->app->singleton(DunningConfig::class, static function (Application $app): DunningConfig {
            $config = $app->make(Config::class)->get('billing.payment.dunning', []);

            return DunningConfig::fromArray(is_array($config) ? $config : []);
        });

        $this->app->singleton(DunningRunner::class, static fn (Application $app): DunningRunner => new DunningRunner(
            $app->make(DelinquencyPolicy::class),
            $app->make(AccountStanding::class),
            $app->make(DunningStateStore::class),
            $app->make(DelinquentAllowList::class),
            $app->make(DunningConfig::class),
        ));

        // Webhook seam. Deny-by-default: with no adapter installed nothing is trusted, so
        // the verifier refuses every payload. The dedup, settle-once, and effect stores
        // default to the zero-config in-memory implementations; a host binds durable ones
        // (and a real applier that writes the invoice's paid state) on the same contracts.
        $this->app->singleton(WebhookVerifier::class, static fn (): DenyingWebhookVerifier => new DenyingWebhookVerifier);

        $this->app->singleton(ProcessedEventStore::class, static fn (): InMemoryProcessedEventStore => new InMemoryProcessedEventStore);

        $this->app->singleton(SettledPaymentStore::class, static fn (): InMemorySettledPaymentStore => new InMemorySettledPaymentStore);

        $this->app->singleton(InvoicePaymentApplier::class, static fn (): InMemoryInvoicePaymentApplier => new InMemoryInvoicePaymentApplier);

        $this->app->singleton(WebhookIngest::class, static fn (Application $app): DefaultWebhookIngest => new DefaultWebhookIngest(
            $app->make(ProcessedEventStore::class),
            $app->make(SettledPaymentStore::class),
            $app->make(InvoicePaymentApplier::class),
        ));
    }
}
