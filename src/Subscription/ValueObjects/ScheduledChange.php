<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use DateTimeImmutable;

/**
 * A pending change to a subscription's price, taking effect at a future date. It
 * is mutable until then — it can be replaced or cleared before it applies.
 */
readonly class ScheduledChange
{
    public function __construct(
        public string $newPriceId,
        public DateTimeImmutable $effectiveAt,
    ) {}
}
