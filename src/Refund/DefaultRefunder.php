<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund;

use Cbox\Billing\Events\CreditNoteIssued;
use Cbox\Billing\Invoice\Contracts\CreditNoteNumberSequence;
use Cbox\Billing\Invoice\ValueObjects\CreditNote;
use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Quote\ValueObjects\QuoteLine;
use Cbox\Billing\Refund\Contracts\Refunder;
use Cbox\Billing\Refund\Contracts\RefundRepository;
use Cbox\Billing\Refund\Enums\ReversalKind;
use Cbox\Billing\Refund\Exceptions\CannotRefund;
use Cbox\Billing\Refund\Support\ReversalPosting;
use Cbox\Billing\Refund\ValueObjects\Refund;
use Cbox\Billing\Refund\ValueObjects\RefundRequest;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The default voluntary-refund flow. For a request it:
 *
 *  1. short-circuits on the request id if already refunded (idempotent no-op replay);
 *  2. computes the reversed net/tax/gross — a FULL refund mirrors every invoice line
 *     negated; a PARTIAL refund reverses `net` and the tax proportional to it;
 *  3. refuses the over-refund (cumulative gross > charged) and the degenerate cases
 *     (unissued invoice, non-positive amount, currency mismatch) — deny-by-default;
 *  4. draws a credit-note number off the seller's own sequence and builds the note;
 *  5. posts the reversing transaction to the ledger (append-only, idempotent on
 *     `(account, refund, id)`);
 *  6. issues the money movement through the payment gateway with a scoped idempotency
 *     key;
 *  7. if asked, reverses a purchase-issued credit grant with an OFFSETTING grant (never
 *     a silent balance edit);
 *  8. records the refund so the replay in step 1 and the cap in step 3 hold.
 *
 * The number is drawn only AFTER the idempotency and over-refund guards pass, so a
 * retry never burns a legal number and the cumulative refund never exceeds the charge.
 */
readonly class DefaultRefunder implements Refunder
{
    public function __construct(
        private CreditNoteNumberSequence $sequence,
        private RefundRepository $refunds,
        private Ledger $ledger,
        private PaymentGateway $gateway,
        private Wallet $wallet,
        private ?Dispatcher $events = null,
    ) {}

    public function refund(RefundRequest $request): Refund
    {
        $existing = $this->refunds->forId($request->id);
        if ($existing !== null) {
            return $existing; // idempotent: a retry / re-delivered event returns the issued refund
        }

        $invoice = $request->invoice;
        if ($invoice->number === '') {
            throw CannotRefund::unissuedInvoice();
        }

        [$net, $tax, $gross, $lines] = $this->computeReversal($request);

        if (! $gross->isPositive()) {
            throw CannotRefund::nonPositiveAmount();
        }

        $alreadyRefunded = $this->refunds->refundedGross($invoice->number, $invoice->currency);
        if ($alreadyRefunded->plus($gross)->compareTo($invoice->totals->gross) > 0) {
            throw CannotRefund::exceedsCharged($gross, $invoice->totals->gross, $alreadyRefunded);
        }

        $number = $this->sequence->next($invoice->seller);

        $creditNote = new CreditNote(
            number: $number,
            invoiceNumber: $invoice->number,
            seller: $invoice->seller,
            account: $request->account,
            currency: $invoice->currency,
            lines: $lines,
            net: $net->negated(),
            tax: $tax->negated(),
            gross: $gross->negated(),
            reason: $request->reason,
            kind: ReversalKind::Voluntary,
            issuedAt: $request->at,
        );

        $transactionId = 'refund:'.$request->id;
        $this->ledger->post(ReversalPosting::build(
            account: $request->account,
            seller: $invoice->seller,
            net: $net,
            tax: $tax,
            gross: $gross,
            kind: ReversalKind::Voluntary,
            reference: $request->id,
            transactionId: $transactionId,
            memo: sprintf('Refund %s against invoice %s (credit note %s)', $request->id, $invoice->number, $number),
        ));

        $gatewayResult = $this->gateway->refund(new RefundIntent(
            id: $transactionId,
            amount: $gross,
            reference: $number,
            idempotencyKey: $transactionId,
            originalGatewayReference: $request->originalGatewayReference,
        ));

        $grantReversalId = $this->reverseGrant($request);

        $refund = new Refund(
            id: $request->id,
            creditNote: $creditNote,
            account: $request->account,
            gross: $gross,
            gatewayResult: $gatewayResult,
            grantReversalId: $grantReversalId,
            ledgerTransactionId: $transactionId,
            kind: ReversalKind::Voluntary,
        );

        $this->refunds->save($refund);

        // Fires once per issued credit note: the idempotent replay above returns before
        // reaching here, so a retried/re-delivered refund never re-announces the note.
        $this->events?->dispatch(new CreditNoteIssued($creditNote));

        return $refund;
    }

    /**
     * Reverse the sale into positive net/tax/gross magnitudes and the negated credit-
     * note lines.
     *
     * @return array{0: Money, 1: Money, 2: Money, 3: list<QuoteLine>}
     */
    private function computeReversal(RefundRequest $request): array
    {
        $invoice = $request->invoice;
        $totals = $invoice->totals;

        if ($request->isFull()) {
            return [$totals->net, $totals->tax, $totals->gross, $this->mirroredLines($invoice->lines)];
        }

        $net = $request->net;
        if ($net === null) {
            throw CannotRefund::nonPositiveAmount();
        }
        if ($net->currency() !== $invoice->currency) {
            throw CannotRefund::currencyMismatch($net->currency(), $invoice->currency);
        }
        if (! $net->isPositive()) {
            throw CannotRefund::nonPositiveAmount();
        }
        if ($net->compareTo($totals->net) > 0) {
            throw CannotRefund::exceedsCharged($net, $totals->net, Money::zero($invoice->currency));
        }

        // Tax reversed in proportion to the net refunded: tax * (net / invoiceNet).
        $tax = $totals->net->isZero()
            ? Money::zero($invoice->currency)
            : $totals->tax->proratedBy($net->minor(), $totals->net->minor());
        $gross = $net->plus($tax);

        $line = new QuoteLine(
            description: sprintf('Partial refund of invoice %s', $invoice->number),
            quantity: 1,
            net: $net->negated(),
            tax: $tax->negated(),
            gross: $gross->negated(),
            treatment: null,
            taxRatePercentage: null,
            taxNote: 'Tax reversed in proportion to the net amount refunded.',
        );

        return [$net, $tax, $gross, [$line]];
    }

    /**
     * The negative mirror of the invoice lines: every monetary field negated, the
     * description/quantity/tax metadata preserved.
     *
     * @param  list<QuoteLine>  $lines
     * @return list<QuoteLine>
     */
    private function mirroredLines(array $lines): array
    {
        return array_map(static fn (QuoteLine $line): QuoteLine => new QuoteLine(
            description: $line->description,
            quantity: $line->quantity,
            net: $line->net->negated(),
            tax: $line->tax->negated(),
            gross: $line->gross->negated(),
            treatment: $line->treatment,
            taxRatePercentage: $line->taxRatePercentage,
            taxNote: $line->taxNote,
        ), $lines);
    }

    /**
     * Reverse a purchase-issued credit grant by depositing an OFFSETTING grant (negative
     * remainder) under a deterministic id, so a replay overwrites rather than double-
     * reverses. Returns the offset grant's id, or `null` when no grant reversal was asked.
     */
    private function reverseGrant(RefundRequest $request): ?string
    {
        $grant = $request->reverseGrant;
        if ($grant === null) {
            return null;
        }

        $units = $request->reverseGrantUnits ?? $grant->remaining;
        $reversalId = 'refund:'.$request->id.':grant-reversal';

        $this->wallet->grant(new CreditGrant(
            id: $reversalId,
            org: $request->account,
            pool: $grant->pool,
            denomination: $grant->denomination,
            remaining: -$units,
            expiresAt: $grant->expiresAt,
            priority: $grant->priority,
            grantedAt: $grant->grantedAt,
            kind: $grant->kind,
            cadence: $grant->cadence,
        ));

        return $reversalId;
    }
}
