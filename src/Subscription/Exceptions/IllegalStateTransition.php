<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Exceptions;

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\SubscriptionManager;
use RuntimeException;

/**
 * Raised when a caller asks the {@see SubscriptionManager}
 * for a status transition the state machine does not permit. The machine is
 * deny-by-default: any `(from → to)` pair not explicitly allowed is refused here rather
 * than silently applied, so an out-of-order lifecycle call (e.g. resuming a canceled
 * subscription, or pausing during a trial) fails loudly.
 *
 * Carries the `from`/`to` states so a caller can surface a precise reason.
 */
class IllegalStateTransition extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly SubscriptionStatus $from,
        public readonly SubscriptionStatus $to,
    ) {
        parent::__construct($message);
    }

    public static function between(SubscriptionStatus $from, SubscriptionStatus $to): self
    {
        return new self(
            message: "Illegal subscription state transition [{$from->value}] → [{$to->value}].",
            from: $from,
            to: $to,
        );
    }
}
