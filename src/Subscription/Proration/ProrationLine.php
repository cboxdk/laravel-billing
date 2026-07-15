<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Proration;

use Cbox\Billing\Money\Money;

/**
 * One line of a proration, already rounded to whole minor units by the gateway's
 * rounding mode. The amount is signed: positive charges the customer (a fresh period
 * or an upgrade delta), negative credits them (the unused part of the base on a
 * reset). Lines are rounded independently and only then summed — never round the
 * combined total.
 */
readonly class ProrationLine
{
    public function __construct(
        public string $description,
        public Money $amount,
    ) {}
}
