<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Testing;

use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Refund\Contracts\ChargebackHandler;
use Cbox\Billing\Refund\ValueObjects\Chargeback;
use Cbox\Billing\Refund\ValueObjects\ChargebackNotice;

/**
 * A recording {@see ChargebackHandler} for consumer tests: it captures every dispute
 * notice and returns the {@see Chargeback} it would record (flagging the account
 * disputed), without touching a ledger or standing store. Honours the contract's
 * idempotency on the dispute reference — a re-delivered notice returns the first
 * chargeback and records no second call.
 */
class FakeChargebackHandler implements ChargebackHandler
{
    /** @var list<ChargebackNotice> */
    public array $notices = [];

    /** @var array<string, Chargeback> keyed by dispute reference */
    private array $recorded = [];

    public function handle(ChargebackNotice $notice): Chargeback
    {
        if (isset($this->recorded[$notice->disputeReference])) {
            return $this->recorded[$notice->disputeReference];
        }

        $this->notices[] = $notice;

        $chargeback = new Chargeback(
            disputeReference: $notice->disputeReference,
            account: $notice->account,
            invoiceNumber: $notice->invoiceNumber,
            gross: $notice->gross(),
            reason: $notice->reason,
            ledgerTransactionId: 'chargeback:'.$notice->disputeReference,
            standingApplied: AccountStandingState::Disputed,
            recordedAt: $notice->occurredAt,
        );

        return $this->recorded[$notice->disputeReference] = $chargeback;
    }
}
