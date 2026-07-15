<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * The bottom line of a quote: net, tax and gross, the credit applied from the
 * wallet, and the amount actually due now.
 */
readonly class QuoteTotals
{
    public function __construct(
        public Money $net,
        public Money $tax,
        public Money $gross,
        public Money $credit,
        public Money $dueNow,
    ) {}
}
