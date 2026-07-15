<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice;

use Cbox\Billing\Invoice\Contracts\InvoiceNumberSequence;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\Sequences\InMemoryInvoiceNumberSequence;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the invoicer and a default in-memory number sequence. Hosts rebind the
 * sequence with a durable, transactional implementation for production numbering.
 */
class InvoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvoiceNumberSequence::class, static fn (): InMemoryInvoiceNumberSequence => new InMemoryInvoiceNumberSequence);

        $this->app->singleton(Invoicer::class, static fn (Application $app): DefaultInvoicer => new DefaultInvoicer(
            $app->make(InvoiceNumberSequence::class),
        ));
    }
}
