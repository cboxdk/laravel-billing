<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice;

use Cbox\Billing\Invoice\Contracts\InvoiceNumberSequence;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\Exceptions\CannotInvoicePendingQuote;
use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use DateTimeImmutable;

/**
 * Issues an invoice by fixing a confirmed quote to the next number from the
 * entity's sequence. A tax-pending quote is refused — an invoice must show a final
 * amount.
 */
readonly class DefaultInvoicer implements Invoicer
{
    public function __construct(private InvoiceNumberSequence $sequence) {}

    public function issue(Quote $quote, SellerEntity $seller, DateTimeImmutable $at): Invoice
    {
        if (! $quote->isTaxResolved()) {
            throw CannotInvoicePendingQuote::forEntity($seller);
        }

        return new Invoice(
            $this->sequence->next($seller),
            $seller,
            $quote->place,
            $quote->currency,
            $quote->lines,
            $quote->totals,
            $at,
        );
    }
}
