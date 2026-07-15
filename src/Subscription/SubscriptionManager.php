<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\ScheduledChange;
use Cbox\Billing\Subscription\ValueObjects\Subscription;
use DateTimeImmutable;

/**
 * Pure lifecycle transitions for a subscription — each returns a new immutable
 * instance. Cancellation is deferred to period end; price changes can be scheduled
 * (and are mutable until they apply); renewal advances the period, enacts a due
 * cancellation, and applies a due scheduled change.
 */
readonly class SubscriptionManager
{
    public function create(string $id, string $organizationId, string $productId, string $priceId, BillingPeriod $period): Subscription
    {
        return new Subscription($id, $organizationId, $productId, $priceId, $period);
    }

    public function cancelAtPeriodEnd(Subscription $subscription): Subscription
    {
        return $this->copy($subscription, cancelAtPeriodEnd: true);
    }

    public function resume(Subscription $subscription): Subscription
    {
        return $this->copy($subscription, cancelAtPeriodEnd: false);
    }

    /** Schedule (or replace) a price change — mutable until it applies. */
    public function scheduleChange(Subscription $subscription, string $newPriceId, DateTimeImmutable $effectiveAt): Subscription
    {
        return $this->copy($subscription, pendingChange: new ScheduledChange($newPriceId, $effectiveAt));
    }

    public function clearScheduledChange(Subscription $subscription): Subscription
    {
        return $this->copy($subscription, clearPendingChange: true);
    }

    /**
     * Advance to the next period: a due cancellation ends the subscription; a due
     * scheduled change re-pins the price; otherwise the price carries over.
     */
    public function renew(Subscription $subscription, BillingPeriod $nextPeriod): Subscription
    {
        if ($subscription->cancelAtPeriodEnd) {
            return $this->copy($subscription, status: SubscriptionStatus::Canceled);
        }

        $change = $subscription->pendingChange;

        if ($change !== null && $change->effectiveAt <= $nextPeriod->start) {
            return $this->copy($subscription, priceId: $change->newPriceId, period: $nextPeriod, clearPendingChange: true);
        }

        return $this->copy($subscription, period: $nextPeriod);
    }

    private function copy(
        Subscription $subscription,
        ?string $priceId = null,
        ?BillingPeriod $period = null,
        ?SubscriptionStatus $status = null,
        ?bool $cancelAtPeriodEnd = null,
        ?ScheduledChange $pendingChange = null,
        bool $clearPendingChange = false,
    ): Subscription {
        return new Subscription(
            $subscription->id,
            $subscription->organizationId,
            $subscription->productId,
            $priceId ?? $subscription->priceId,
            $period ?? $subscription->period,
            $status ?? $subscription->status,
            $cancelAtPeriodEnd ?? $subscription->cancelAtPeriodEnd,
            $clearPendingChange ? null : ($pendingChange ?? $subscription->pendingChange),
        );
    }
}
