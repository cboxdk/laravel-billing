<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange\Exceptions;

use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use RuntimeException;

/**
 * Raised by the plan-change flow when the {@see TransitionPolicy}
 * refuses the target: the change is **refused before proration**, never silently
 * prorated (ADR-0010). Carries the policy's reason and the plan ids, so the caller can
 * surface "requires migration / contact sales" instead of a price.
 */
class TransitionNotAllowed extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $fromPlanId,
        public readonly string $toPlanId,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function because(Product $from, Product $to, string $reason): self
    {
        return new self(
            message: "Cannot change from plan [{$from->id}] to [{$to->id}]: {$reason}",
            fromPlanId: $from->id,
            toPlanId: $to->id,
            reason: $reason,
        );
    }
}
