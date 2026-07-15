<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice;

use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Invoice\Contracts\CreditNoteNumberSequence;
use Cbox\Billing\Invoice\Contracts\InvoiceNumberSequence;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\Sequences\InMemoryCreditNoteNumberSequence;
use Cbox\Billing\Invoice\Sequences\InMemoryInvoiceNumberSequence;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the invoicer and the legal-document number sequences (invoice and credit
 * note, each its own per-entity sequence). Hosts rebind the sequences with durable,
 * transactional implementations for production numbering. The invoicer resolves the
 * {@see BillingCurrencyLock} that fixes an account's currency on its first finalized
 * invoice.
 */
class InvoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvoiceNumberSequence::class, static fn (): InMemoryInvoiceNumberSequence => new InMemoryInvoiceNumberSequence);

        $this->app->singleton(CreditNoteNumberSequence::class, static fn (): InMemoryCreditNoteNumberSequence => new InMemoryCreditNoteNumberSequence);

        $this->app->singleton(Invoicer::class, static fn (Application $app): DefaultInvoicer => new DefaultInvoicer(
            $app->make(InvoiceNumberSequence::class),
            $app->make(BillingCurrencyLock::class),
        ));
    }
}
