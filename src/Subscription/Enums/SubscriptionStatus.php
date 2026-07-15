<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

/**
 * Subscription state. A cancellation scheduled for period end keeps the
 * subscription `Active` (with `cancelAtPeriodEnd`) until it actually ends.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Canceled = 'canceled';
}
