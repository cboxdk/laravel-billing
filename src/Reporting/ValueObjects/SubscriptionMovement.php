<?php

declare(strict_types=1);

namespace Cbox\Billing\Reporting\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\MrrMovement;
use InvalidArgumentException;

/**
 * One subscription's monthly-recurring contribution at two points in time — the
 * atomic input to an {@see MrrMovement} decomposition.
 *
 * Both amounts are in the same currency (a subscription is billed in one currency);
 * a subscription that did not exist at a point contributes {@see Money::zero()} for
 * that point. `$returning` disambiguates the zero→positive transition: a brand-new
 * logo counts as **new**, whereas a previously-churned customer coming back counts
 * as **reactivation**. It is only consulted when `$startMrr` is zero and `$endMrr`
 * is positive.
 */
readonly class SubscriptionMovement
{
    public function __construct(
        public string $subscriptionId,
        public Money $startMrr,
        public Money $endMrr,
        public bool $returning = false,
    ) {
        if ($startMrr->currency() !== $endMrr->currency()) {
            throw new InvalidArgumentException(
                "Start and end MRR must share a currency; got {$startMrr->currency()} and {$endMrr->currency()}."
            );
        }
    }

    /** The single currency this movement is denominated in. */
    public function currency(): string
    {
        return $this->startMrr->currency();
    }
}
