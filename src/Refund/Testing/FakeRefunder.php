<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Testing;

use Cbox\Billing\Invoice\ValueObjects\CreditNote;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Refund\Contracts\Refunder;
use Cbox\Billing\Refund\DefaultRefunder;
use Cbox\Billing\Refund\Enums\ReversalKind;
use Cbox\Billing\Refund\ValueObjects\Refund;
use Cbox\Billing\Refund\ValueObjects\RefundRequest;

/**
 * A recording {@see Refunder} for consumer tests: it captures every request and
 * returns a benign, shape-faithful {@see Refund} (a credit note reversing the invoice
 * totals for a full refund, or the requested net plus proportional tax for a partial
 * one) without touching a ledger, gateway or wallet. Substitute it for the real
 * {@see DefaultRefunder} to assert a caller refunds the right
 * invoice for the right amount. It honours the contract's idempotency: a repeated
 * request id returns the first refund and records no second call.
 */
class FakeRefunder implements Refunder
{
    /** @var list<RefundRequest> */
    public array $requests = [];

    /** @var array<string, Refund> keyed by request id */
    private array $issued = [];

    public function refund(RefundRequest $request): Refund
    {
        if (isset($this->issued[$request->id])) {
            return $this->issued[$request->id];
        }

        $this->requests[] = $request;

        $invoice = $request->invoice;
        $totals = $invoice->totals;

        if ($request->isFull()) {
            $net = $totals->net;
            $tax = $totals->tax;
        } else {
            $net = $request->net ?? Money::zero($invoice->currency);
            $tax = $totals->net->isZero()
                ? Money::zero($invoice->currency)
                : $totals->tax->proratedBy($net->minor(), $totals->net->minor());
        }

        $gross = $net->plus($tax);

        $creditNote = new CreditNote(
            number: 'CN-FAKE-'.$request->id,
            invoiceNumber: $invoice->number,
            seller: $invoice->seller,
            account: $request->account,
            currency: $invoice->currency,
            lines: [],
            net: $net->negated(),
            tax: $tax->negated(),
            gross: $gross->negated(),
            reason: $request->reason,
            kind: ReversalKind::Voluntary,
            issuedAt: $request->at,
        );

        $refund = new Refund(
            id: $request->id,
            creditNote: $creditNote,
            account: $request->account,
            gross: $gross,
            gatewayResult: new PaymentResult(PaymentStatus::Pending, 'fake:'.$request->id),
            grantReversalId: $request->reverseGrant === null ? null : 'refund:'.$request->id.':grant-reversal',
            ledgerTransactionId: 'refund:'.$request->id,
            kind: ReversalKind::Voluntary,
        );

        return $this->issued[$request->id] = $refund;
    }
}
