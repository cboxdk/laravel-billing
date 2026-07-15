<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * Recurring revenue for one currency: monthly (MRR), annualised (ARR), and the
 * number of subscriptions behind it.
 */
readonly class MrrLine
{
    public function __construct(
        public string $currency,
        public Money $mrr,
        public Money $arr,
        public int $subscriptions,
    ) {}
}
