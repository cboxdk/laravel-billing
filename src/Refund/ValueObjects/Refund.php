<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\ValueObjects;

use Cbox\Billing\Invoice\ValueObjects\CreditNote;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Refund\Enums\ReversalKind;

/**
 * The outcome of a voluntary refund: the {@see CreditNote} it issued, the positive
 * `gross` amount returned to the customer, the gateway's result for the money
 * movement, the id of the offsetting grant if credit was reversed (else `null`), and
 * the id of the reversing ledger transaction. Immutable — the record of what happened.
 */
readonly class Refund
{
    public function __construct(
        public string $id,
        public CreditNote $creditNote,
        public string $account,
        public Money $gross,
        public PaymentResult $gatewayResult,
        public ?string $grantReversalId,
        public string $ledgerTransactionId,
        public ReversalKind $kind,
    ) {}

    /** The invoice this refund reverses. */
    public function invoiceNumber(): string
    {
        return $this->creditNote->invoiceNumber;
    }
}
