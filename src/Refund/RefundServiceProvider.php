<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund;

use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Invoice\Contracts\CreditNoteNumberSequence;
use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Refund\Contracts\ChargebackHandler;
use Cbox\Billing\Refund\Contracts\ChargebackRegister;
use Cbox\Billing\Refund\Contracts\Refunder;
use Cbox\Billing\Refund\Contracts\RefundRepository;
use Cbox\Billing\Refund\Storage\InMemoryChargebackRegister;
use Cbox\Billing\Refund\Storage\InMemoryRefundRepository;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the refund + chargeback flows and their stores. The stores default to the
 * zero-config in-memory implementations; hosts rebind them to durable stores on the
 * ledger's connection so a refund/dispute record and its reversing posting commit
 * together. The flows depend only on contracts (ledger, gateway, wallet, sequences,
 * account standing), never on concretes.
 */
class RefundServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RefundRepository::class, static fn (): InMemoryRefundRepository => new InMemoryRefundRepository);

        $this->app->singleton(ChargebackRegister::class, static fn (): InMemoryChargebackRegister => new InMemoryChargebackRegister);

        $this->app->singleton(Refunder::class, static fn (Application $app): DefaultRefunder => new DefaultRefunder(
            $app->make(CreditNoteNumberSequence::class),
            $app->make(RefundRepository::class),
            $app->make(Ledger::class),
            $app->make(PaymentGateway::class),
            $app->make(Wallet::class),
            $app->make(Dispatcher::class),
        ));

        $this->app->singleton(ChargebackHandler::class, static fn (Application $app): DefaultChargebackHandler => new DefaultChargebackHandler(
            $app->make(ChargebackRegister::class),
            $app->make(Ledger::class),
            $app->make(AccountStanding::class),
        ));
    }
}
