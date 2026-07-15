<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Contracts;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * The host effect a settled webhook applies: mark the invoice behind `$reference` paid.
 * This is the seam the host owns — the engine drives it exactly once through
 * {@see WebhookIngest}, guarded by {@see SettledPaymentStore}, so the applier itself does
 * not have to be idempotent.
 *
 * In production the durable implementation writes the invoice's paid state, and — for
 * the exactly-once guarantee — that write commits in the SAME transaction as the
 * settle-once claim (see {@see SettledPaymentStore}).
 */
interface InvoicePaymentApplier
{
    /**
     * Apply the settled payment to the invoice referenced by `$reference` for `$amount`,
     * carrying the mapped gateway result (its `gatewayReference` is the reconciliation
     * handle). Called at most once per reference by the ingest.
     */
    public function markPaid(string $reference, Money $amount, PaymentResult $result): void;
}
