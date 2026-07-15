<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\ValueObjects\QuoteLine;
use Cbox\Billing\Refund\Enums\RefundReason;
use Cbox\Billing\Refund\Enums\ReversalKind;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use DateTimeImmutable;

/**
 * A credit note: the legal reversal of (part of) an issued invoice. It carries its
 * OWN number from the selling entity's credit-note sequence — never an invoice number,
 * never a reused one — and references the original invoice it reverses.
 *
 * Its `lines`, `net`, `tax` and `gross` are the NEGATIVE mirror of what is being
 * reversed: a refund reverses the tax it charged, so the tax is mirrored negatively
 * too, in proportion to the amount refunded. A full refund mirrors every invoice line
 * negated; a partial refund carries a single line for the reversed portion. `gross`
 * (net + tax, negative) is what is returned to the customer.
 */
readonly class CreditNote
{
    /**
     * @param  list<QuoteLine>  $lines  the reversed lines, monetary amounts negative
     */
    public function __construct(
        public string $number,
        public string $invoiceNumber,
        public SellerEntity $seller,
        public string $account,
        public string $currency,
        public array $lines,
        public Money $net,
        public Money $tax,
        public Money $gross,
        public RefundReason $reason,
        public ReversalKind $kind,
        public DateTimeImmutable $issuedAt,
    ) {}
}
