<?php

declare(strict_types=1);

namespace Cbox\Billing\Events;

use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\Subscription;

/**
 * A subscription advanced onto its next period: {@see SubscriptionManager::renew()}
 * carried the price over (or re-pinned a due scheduled change) into the new period.
 * Fires once per renewal. A renewal that instead enacts a due cancellation (the
 * subscription was `cancelAtPeriodEnd`) ends the subscription rather than renewing it and
 * does NOT fire this.
 *
 * Carries both the `$previous` subscription and the renewed `$subscription`, so a listener
 * sees exactly what changed (the advanced period, and any price re-pin).
 */
readonly class SubscriptionRenewed
{
    public function __construct(
        public Subscription $previous,
        public Subscription $subscription,
    ) {}
}
