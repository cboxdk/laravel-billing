<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Contracts;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Refund\ValueObjects\Refund;

/**
 * The record of issued refunds — what makes a refund idempotent and bounds it to the
 * amount charged.
 *
 *  - {@see forId()} returns an already-issued refund for a request id, so a retry is a
 *    no-op that returns it (short-circuiting BEFORE a credit-note number is drawn).
 *  - {@see refundedGross()} is the cumulative gross already refunded against an
 *    invoice (a positive magnitude), so the refunder can refuse an over-refund.
 */
interface RefundRepository
{
    public function forId(string $refundId): ?Refund;

    public function refundedGross(string $invoiceNumber, string $currency): Money;

    public function save(Refund $refund): void;
}
