<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\ValueObjects\AddOn;
use Cbox\Billing\Subscription\ValueObjects\BillingCycle;
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

    /**
     * Create a subscription anchored on a {@see BillingCycle}: the opening period is the
     * cycle's period containing `$startAt`, and the cycle is carried so renewals advance
     * on the real month-end-clamped anchor rather than an assumed window (ADR-0012).
     */
    public function createOnCycle(string $id, string $organizationId, string $productId, string $priceId, BillingCycle $cycle, DateTimeImmutable $startAt): Subscription
    {
        return new Subscription(
            $id,
            $organizationId,
            $productId,
            $priceId,
            $cycle->periodContaining($startAt),
            cycle: $cycle,
        );
    }

    public function cancelAtPeriodEnd(Subscription $subscription): Subscription
    {
        return $this->copy($subscription, cancelAtPeriodEnd: true);
    }

    public function resume(Subscription $subscription): Subscription
    {
        return $this->copy($subscription, cancelAtPeriodEnd: false);
    }

    /**
     * Cancel immediately — the org ends on no plan right now (not at period end). This
     * is the cancel-to-null transition ADR-0006 keys forfeiture on; the pure engine
     * only produces the ended state, the lifecycle wires the forfeiture.
     */
    public function cancelNow(Subscription $subscription): Subscription
    {
        return $this->copy($subscription, status: SubscriptionStatus::Canceled, cancelAtPeriodEnd: false);
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

    /** Attach (or replace) an add-on — matched by id, so re-adding one swaps it in place. */
    public function addAddOn(Subscription $subscription, AddOn $addOn): Subscription
    {
        $addOns = array_values(array_filter(
            $subscription->addOns,
            static fn (AddOn $existing): bool => $existing->id !== $addOn->id,
        ));
        $addOns[] = $addOn;

        return $this->copy($subscription, addOns: $addOns);
    }

    /** Detach an add-on by id; a no-op when it is not attached. */
    public function removeAddOn(Subscription $subscription, string $addOnId): Subscription
    {
        return $this->copy($subscription, addOns: array_values(array_filter(
            $subscription->addOns,
            static fn (AddOn $existing): bool => $existing->id !== $addOnId,
        )));
    }

    /**
     * Advance a cycle-anchored subscription onto its next period, computed from the
     * carried {@see BillingCycle} at `$at` — the renewal counterpart to
     * {@see createOnCycle()}. Falls back to a plain renewal into the caller-supplied
     * period when the subscription carries no cycle.
     */
    public function renewOnCycle(Subscription $subscription, DateTimeImmutable $at): Subscription
    {
        $cycle = $subscription->cycle;

        if ($cycle === null) {
            return $subscription;
        }

        return $this->renew($subscription, $cycle->nextPeriod($at));
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

    /**
     * @param  list<AddOn>|null  $addOns
     */
    private function copy(
        Subscription $subscription,
        ?string $priceId = null,
        ?BillingPeriod $period = null,
        ?SubscriptionStatus $status = null,
        ?bool $cancelAtPeriodEnd = null,
        ?ScheduledChange $pendingChange = null,
        bool $clearPendingChange = false,
        ?array $addOns = null,
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
            $subscription->cycle,
            $addOns ?? $subscription->addOns,
        );
    }
}
