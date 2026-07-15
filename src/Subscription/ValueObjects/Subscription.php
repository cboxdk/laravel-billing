<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\SubscriptionManager;

/**
 * A subscription: an organization on a product at a pinned price for the current
 * billing period. Immutable — lifecycle transitions produce new instances via the
 * {@see SubscriptionManager}.
 */
readonly class Subscription
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $productId,
        public string $priceId,
        public BillingPeriod $period,
        public SubscriptionStatus $status = SubscriptionStatus::Active,
        public bool $cancelAtPeriodEnd = false,
        public ?ScheduledChange $pendingChange = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }
}
