<?php

declare(strict_types=1);

namespace Cbox\Billing\Events;

use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\ValueObjects\Invoice;

/**
 * An invoice was issued: {@see Invoicer::issue()} fixed a confirmed quote to a legal
 * number and finalized it under the account's currency lock. Fires once per issued
 * invoice, after finalization succeeds — a refused issuance (tax-pending quote, currency
 * mismatch) throws before this fires.
 *
 * Carries the full {@see Invoice} aggregate (number, seller, lines, totals, issue date)
 * and the billing `$account` it was issued for, so a listener needs no follow-up lookup.
 */
readonly class InvoiceIssued
{
    public function __construct(
        public Invoice $invoice,
        public string $account,
    ) {}
}
