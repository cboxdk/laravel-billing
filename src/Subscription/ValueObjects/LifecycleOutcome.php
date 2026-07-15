<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Subscription\SubscriptionLifecycle;
use Cbox\Billing\Wallet\ValueObjects\RemovalReport;

/**
 * The result of a lifecycle step run through the {@see SubscriptionLifecycle}:
 * the resulting `subscription` (null when the org ended on no plan) paired with the
 * `forfeited` report — what, if anything, the transition zeroed in the wallet. The
 * report is empty for a step that did not leave-without-landing.
 */
readonly class LifecycleOutcome
{
    public function __construct(
        public ?Subscription $subscription,
        public RemovalReport $forfeited,
    ) {}
}
