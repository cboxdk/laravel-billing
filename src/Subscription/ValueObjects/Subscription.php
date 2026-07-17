<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\SubscriptionManager;
use DateTimeImmutable;

/**
 * A subscription: an organization on a product at a pinned price for the current
 * billing period. Immutable — lifecycle transitions produce new instances via the
 * {@see SubscriptionManager}.
 *
 * It carries the {@see BillingCycle} that produced its current period (when anchored,
 * ADR-0012) so renewals can advance on the real month-end-clamped cycle, and any
 * {@see AddOn}s attached to it — each aligned to this cycle or running on its own.
 *
 * The lifecycle extensions ride on trailing, optional constructor arguments so the
 * value object stays backward-compatible:
 *  - `$trialEndsAt`       — when a trial converts (set while {@see SubscriptionStatus::Trialing}).
 *  - `$pausedAt`          — when the subscription was paused (set while {@see SubscriptionStatus::Paused}).
 *  - `$periodIndex`       — the 0-based index of the current period within the term, advanced on each renewal; a {@see RampSchedule} is resolved against it.
 *  - `$ramp`              — an optional ramp deal stepping the recurring price over the term.
 *  - `$minimumCommitment` — an optional per-period spend floor producing a true-up.
 */
readonly class Subscription
{
    /**
     * @param  list<AddOn>  $addOns
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $productId,
        public string $priceId,
        public BillingPeriod $period,
        public SubscriptionStatus $status = SubscriptionStatus::Active,
        public bool $cancelAtPeriodEnd = false,
        public ?ScheduledChange $pendingChange = null,
        public ?BillingCycle $cycle = null,
        public array $addOns = [],
        public ?DateTimeImmutable $trialEndsAt = null,
        public ?DateTimeImmutable $pausedAt = null,
        public int $periodIndex = 0,
        public ?RampSchedule $ramp = null,
        public ?MinimumCommitment $minimumCommitment = null,
    ) {}

    /**
     * Whether the subscription is currently serving its plan — true for the serving
     * states (`Trialing`, `Active`, `PastDue`, `NonRenewing`), false when `Paused` or
     * `Canceled`. A subscription with a cancellation scheduled for period end stays
     * "active" (serving) until it renews into the cancellation, which is what
     * entitlement projection and forfeiture keys on.
     */
    public function isActive(): bool
    {
        return $this->status->isServing();
    }

    public function isTrialing(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    public function isPastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    public function isPaused(): bool
    {
        return $this->status === SubscriptionStatus::Paused;
    }

    public function isNonRenewing(): bool
    {
        return $this->status === SubscriptionStatus::NonRenewing;
    }

    public function isCanceled(): bool
    {
        return $this->status === SubscriptionStatus::Canceled;
    }

    /** Whether an add-on with the given id is attached. */
    public function hasAddOn(string $addOnId): bool
    {
        foreach ($this->addOns as $addOn) {
            if ($addOn->id === $addOnId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The effective recurring amount for the current period, resolved from the ramp
     * step covering {@see $periodIndex}. Null when the subscription carries no ramp (its
     * recurring price is then whatever the catalog pins to {@see $priceId}).
     */
    public function effectiveRecurringAmount(): ?Money
    {
        return $this->ramp?->amountForPeriod($this->periodIndex);
    }

    /**
     * The minimum-commitment true-up for this period given the amount actually charged
     * (recurring + metered). Zero when there is no commitment, or when the period met
     * its floor.
     */
    public function trueUp(Money $actual): Money
    {
        return $this->minimumCommitment?->trueUp($actual) ?? Money::zero($actual->currency());
    }
}
