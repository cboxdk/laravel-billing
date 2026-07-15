<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement;

use Cbox\Billing\Entitlement\Contracts\EntitlementProjector;
use Cbox\Billing\Entitlement\Contracts\EntitlementWriter;
use Cbox\Billing\Entitlement\ValueObjects\Entitlement;
use Cbox\Billing\Subscription\ValueObjects\Subscription;

/**
 * Grants the tier for an active subscription, revokes it otherwise. The
 * subscription id is the source reference, so identity can attribute (and later
 * reconcile) the entitlement to the billing subscription that drove it.
 */
readonly class DefaultEntitlementProjector implements EntitlementProjector
{
    public function __construct(private EntitlementWriter $writer) {}

    public function project(Subscription $subscription, string $tier, array $features = []): void
    {
        if ($subscription->isActive()) {
            $this->writer->set(new Entitlement(
                $subscription->organizationId,
                $subscription->productId,
                $tier,
                $features,
                $subscription->id,
            ));

            return;
        }

        $this->writer->revoke($subscription->organizationId, $subscription->id);
    }
}
