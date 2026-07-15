<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Contracts;

use Cbox\Billing\Refund\Exceptions\CannotRefund;
use Cbox\Billing\Refund\ValueObjects\Refund;
use Cbox\Billing\Refund\ValueObjects\RefundRequest;

/**
 * Issues a voluntary refund against an issued invoice: a credit note off the entity's
 * own sequence (reversing the tax too), a reversing ledger posting, the money movement
 * through the payment gateway, and — when asked — an offsetting credit grant.
 *
 * Deny-by-default: an unissued invoice cannot be refunded, and the cumulative refunded
 * amount can never exceed what was charged. The whole operation is idempotent on the
 * request id — a retry returns the already-issued refund without side effects.
 */
interface Refunder
{
    /**
     * @throws CannotRefund when the invoice is unissued, the amount is non-positive,
     *                      the currency does not match, or the refund would exceed the
     *                      amount charged.
     */
    public function refund(RefundRequest $request): Refund;
}
