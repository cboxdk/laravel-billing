<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Retirement\Exceptions;

use Cbox\Billing\Subscription\ValueObjects\Subscription;
use RuntimeException;

/**
 * Raised when a subscription on a retired plan reaches its forcing renewal with **no
 * choice** made and **no default successor** configured (ADR-0016). Deny-by-default: the
 * renewal is refused rather than silently continuing — and charging — on a retired plan.
 * The host catches this and surfaces the `unresolved-retirement` to the subscriber.
 */
class RetirementNotResolved extends RuntimeException
{
    public function __construct(public readonly Subscription $subscription)
    {
        parent::__construct(
            "Subscription [{$subscription->id}] is on retired plan [{$subscription->productId}] "
            .'with no successor chosen, no cancel scheduled, and no default successor configured; '
            .'the renewal is refused rather than continuing on the retired plan.',
        );
    }
}
