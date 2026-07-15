<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Contracts;

use Cbox\Billing\Subscription\ValueObjects\Subscription;

/**
 * Projects a subscription's state to the coarse entitlement identity holds: an
 * active subscription grants its tier; an inactive one revokes it.
 */
interface EntitlementProjector
{
    /**
     * @param  list<string>  $features
     */
    public function project(Subscription $subscription, string $tier, array $features = []): void;
}
