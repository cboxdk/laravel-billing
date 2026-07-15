<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund;

use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Refund\Contracts\ChargebackHandler;
use Cbox\Billing\Refund\Contracts\ChargebackRegister;
use Cbox\Billing\Refund\Enums\ReversalKind;
use Cbox\Billing\Refund\Exceptions\CannotRefund;
use Cbox\Billing\Refund\Support\ReversalPosting;
use Cbox\Billing\Refund\ValueObjects\Chargeback;
use Cbox\Billing\Refund\ValueObjects\ChargebackNotice;

/**
 * The default chargeback flow. For a dispute notice it:
 *
 *  1. short-circuits on the dispute reference if already recorded (idempotent no-op);
 *  2. posts the reversing transaction to the ledger under the `chargeback` source —
 *     the SAME double-entry as a refund but a distinct source, so a forced reversal is
 *     never confused with a voluntary one in the money source of truth;
 *  3. moves the account's standing to {@see AccountStandingState::Disputed}, gating
 *     access while the dispute is open;
 *  4. records the chargeback so the replay in step 1 holds.
 *
 * It never issues a payment-gateway money movement — unlike a refund, the funds were
 * already pulled by the network out of band. That, plus the `chargeback` ledger source
 * and the {@see ReversalKind::Forced} stamp, is what distinguishes a chargeback from a
 * voluntary refund.
 */
readonly class DefaultChargebackHandler implements ChargebackHandler
{
    public function __construct(
        private ChargebackRegister $register,
        private Ledger $ledger,
        private AccountStanding $standing,
    ) {}

    public function handle(ChargebackNotice $notice): Chargeback
    {
        $existing = $this->register->find($notice->disputeReference);
        if ($existing !== null) {
            return $existing; // idempotent on the dispute reference
        }

        $gross = $notice->gross();
        if (! $gross->isPositive()) {
            throw CannotRefund::nonPositiveAmount();
        }

        $transactionId = 'chargeback:'.$notice->disputeReference;
        $this->ledger->post(ReversalPosting::build(
            account: $notice->account,
            seller: $notice->seller,
            net: $notice->net,
            tax: $notice->tax,
            gross: $gross,
            kind: ReversalKind::Forced,
            reference: $notice->disputeReference,
            transactionId: $transactionId,
            memo: sprintf('Chargeback %s against invoice %s', $notice->disputeReference, $notice->invoiceNumber),
            occurredAt: $notice->occurredAt->getTimestamp(),
        ));

        $this->standing->flag(
            $notice->account,
            AccountStandingState::Disputed,
            'chargeback:'.$notice->disputeReference,
        );

        $chargeback = new Chargeback(
            disputeReference: $notice->disputeReference,
            account: $notice->account,
            invoiceNumber: $notice->invoiceNumber,
            gross: $gross,
            reason: $notice->reason,
            ledgerTransactionId: $transactionId,
            standingApplied: AccountStandingState::Disputed,
            recordedAt: $notice->occurredAt,
        );

        $this->register->record($chargeback);

        return $chargeback;
    }
}
