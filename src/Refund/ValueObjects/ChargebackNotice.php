<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\ValueObjects;

use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use DateTimeImmutable;

/**
 * An externally-initiated dispute notice, as delivered by the acquirer/network. It
 * carries the network's own `disputeReference` — the natural key the handler is
 * idempotent on, so a re-delivered webhook records the dispute once — the disputed
 * `net` and `tax` (so the reversal mirrors the sale's tax too), a free-form `reason`
 * code from the network (kept verbatim; not one of our own enums), and the original
 * charge's gateway reference for reconciliation.
 */
readonly class ChargebackNotice
{
    public function __construct(
        public string $disputeReference,
        public string $account,
        public SellerEntity $seller,
        public string $invoiceNumber,
        public Money $net,
        public Money $tax,
        public string $reason,
        public DateTimeImmutable $occurredAt,
        public ?string $originalGatewayReference = null,
    ) {}

    /**
     * A dispute over the FULL amount of `$invoice` — net and tax taken from the
     * invoice totals, so the ledger reversal mirrors the whole sale including tax.
     */
    public static function forInvoice(
        string $disputeReference,
        string $account,
        Invoice $invoice,
        string $reason,
        DateTimeImmutable $occurredAt,
        ?string $originalGatewayReference = null,
    ): self {
        return new self(
            $disputeReference,
            $account,
            $invoice->seller,
            $invoice->number,
            $invoice->totals->net,
            $invoice->totals->tax,
            $reason,
            $occurredAt,
            $originalGatewayReference,
        );
    }

    /** The disputed gross (net + tax) — the amount the network pulled back. */
    public function gross(): Money
    {
        return $this->net->plus($this->tax);
    }
}
