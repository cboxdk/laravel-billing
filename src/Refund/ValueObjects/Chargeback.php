<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\ValueObjects;

use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Refund\Enums\ReversalKind;
use DateTimeImmutable;

/**
 * A recorded chargeback: the forced, externally-initiated reversal of a sale. Holds
 * the network's `disputeReference`, the disputed `gross`, the network's `reason` code,
 * the id of the reversing ledger transaction, and the account standing the dispute
 * moved the account into (gating access). Its `kind` is always {@see
 * ReversalKind::Forced}, distinguishing it from a voluntary {@see Refund} in the audit
 * trail. No gateway money movement is recorded because we never issue one — the
 * network already pulled the funds.
 */
readonly class Chargeback
{
    public function __construct(
        public string $disputeReference,
        public string $account,
        public string $invoiceNumber,
        public Money $gross,
        public string $reason,
        public string $ledgerTransactionId,
        public AccountStandingState $standingApplied,
        public DateTimeImmutable $recordedAt,
        public ReversalKind $kind = ReversalKind::Forced,
    ) {}
}
