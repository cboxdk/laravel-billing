<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice;

use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Events\InvoiceIssued;
use Cbox\Billing\Invoice\Contracts\InvoiceNumberSequence;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\Exceptions\CannotInvoicePendingQuote;
use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Issues an invoice by fixing a confirmed quote to the next number from the
 * entity's sequence. A tax-pending quote is refused — an invoice must show a final
 * amount.
 *
 * Finalization runs under the account's {@see BillingCurrencyLock}: the first
 * finalized invoice stamps and locks the account's billing currency, and the whole
 * finalization (drawing the number, building the invoice) runs in the same critical
 * section as the stamp, so the stamp and the invoice commit together. A later invoice
 * in a different currency is refused before any number is drawn.
 */
readonly class DefaultInvoicer implements Invoicer
{
    public function __construct(
        private InvoiceNumberSequence $sequence,
        private BillingCurrencyLock $currencyLock,
        private ?Dispatcher $events = null,
    ) {}

    public function issue(Quote $quote, SellerEntity $seller, string $account, DateTimeImmutable $at): Invoice
    {
        if (! $quote->isTaxResolved()) {
            throw CannotInvoicePendingQuote::forEntity($seller);
        }

        $invoice = $this->currencyLock->stampAndGuard(
            $account,
            $quote->currency,
            fn (): Invoice => new Invoice(
                $this->sequence->next($seller),
                $seller,
                $quote->place,
                $quote->currency,
                $quote->lines,
                $quote->totals,
                $at,
            ),
        );

        $this->events?->dispatch(new InvoiceIssued($invoice, $account));

        return $invoice;
    }
}
