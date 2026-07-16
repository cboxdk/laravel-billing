<?php

declare(strict_types=1);

namespace Cbox\Billing\Events;

use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\ScheduledChange;
use Cbox\Billing\Subscription\ValueObjects\Subscription;

/**
 * A subscription's plan was changed: {@see SubscriptionManager::scheduleChange()} pinned
 * (or replaced) a price change on the subscription. This is the engine's plan-change seam
 * — the change is scheduled to take effect at {@see ScheduledChange::$effectiveAt} and is
 * enacted on the next {@see SubscriptionManager::renew()}. Fires once per scheduled change.
 *
 * Carries the `$subscription` with the change pinned and the `$change` itself (the new
 * price id + effective date) so a listener knows exactly what changed and when it lands.
 */
readonly class SubscriptionChanged
{
    public function __construct(
        public Subscription $subscription,
        public ScheduledChange $change,
    ) {}
}
