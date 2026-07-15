<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Contracts;

use Cbox\Billing\Refund\ValueObjects\Chargeback;
use Cbox\Billing\Refund\ValueObjects\ChargebackNotice;

/**
 * Handles an externally-initiated dispute (a chargeback). It records the dispute,
 * posts the reversal to the ledger (under the `chargeback` source, so it is
 * distinguishable from a voluntary refund), and moves the account's standing to gate
 * access. It never issues a payment-gateway money movement — the network already
 * pulled the funds.
 *
 * Idempotent on the notice's dispute reference: a re-delivered dispute webhook records
 * the chargeback once, posts the reversal once, and flags the account once.
 */
interface ChargebackHandler
{
    public function handle(ChargebackNotice $notice): Chargeback;
}
