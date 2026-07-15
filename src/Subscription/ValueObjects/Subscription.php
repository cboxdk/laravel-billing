<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\SubscriptionManager;

/**
 * A subscription: an organization on a product at a pinned price for the current
 * billing period. Immutable — lifecycle transitions produce new instances via the
 * {@see SubscriptionManager}.
 *
 * It carries the {@see BillingCycle} that produced its current period (when anchored,
 * ADR-0012) so renewals can advance on the real month-end-clamped cycle, and any
 * {@see AddOn}s attached to it — each aligned to this cycle or running on its own.
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
    ) {}

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
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
}
