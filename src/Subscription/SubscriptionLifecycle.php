<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Subscription\Contracts\ForfeitureHandler;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\LifecycleOutcome;
use Cbox\Billing\Subscription\ValueObjects\Subscription;
use Cbox\Billing\Subscription\ValueObjects\SubscriptionTransition;

/**
 * The composition seam over the pure {@see SubscriptionManager}: it runs a lifecycle
 * step, then hands the resulting **transition** (before → after) to the
 * {@see ForfeitureHandler}, which forfeits iff the org left without landing. The
 * manager stays a pure transition engine and never touches the wallet; this class is
 * the one place the two are wired, so forfeiture is driven by the transition rather
 * than by any caller remembering to trigger it.
 */
readonly class SubscriptionLifecycle
{
    public function __construct(
        private SubscriptionManager $manager,
        private ForfeitureHandler $forfeiture,
    ) {}

    /** Cancel immediately to no plan; forfeits the org's forfeitable pools. */
    public function cancelNow(Subscription $subscription, int $now): LifecycleOutcome
    {
        $after = $this->manager->cancelNow($subscription);

        return $this->settle(SubscriptionTransition::canceled($subscription), $after, $now);
    }

    /**
     * Advance to the next period. When the step enacts a due cancellation (the sub was
     * `cancelAtPeriodEnd`), the resulting inactive state makes this a left-without-landing
     * transition and the org's forfeitable pools are forfeited; an ordinary renewal is not.
     */
    public function renew(Subscription $subscription, BillingPeriod $nextPeriod, int $now): LifecycleOutcome
    {
        $after = $this->manager->renew($subscription, $nextPeriod);

        return $this->settle(SubscriptionTransition::between($subscription, $after), $after, $now);
    }

    /**
     * Move the org from one subscription onto another. Landing on an active subscription
     * forfeits nothing; a destination that is absent or inactive (a downgrade resolving to
     * no plan) is a left-without-landing transition and forfeits.
     */
    public function switchTo(Subscription $from, ?Subscription $to, int $now): LifecycleOutcome
    {
        return $this->settle(SubscriptionTransition::between($from, $to), $to, $now);
    }

    private function settle(SubscriptionTransition $transition, ?Subscription $after, int $now): LifecycleOutcome
    {
        return new LifecycleOutcome($after, $this->forfeiture->onTransition($transition, $now));
    }
}
