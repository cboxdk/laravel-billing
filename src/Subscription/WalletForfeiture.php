<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Subscription\Contracts\ForfeitureHandler;
use Cbox\Billing\Subscription\ValueObjects\SubscriptionTransition;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\ValueObjects\RemovalReport;

/**
 * The default {@see ForfeitureHandler}: when a transition left a subscription without
 * landing on another, forfeit the departing org's forfeitable pools through the
 * {@see Wallet} contract (floored at zero, so a negative pay-as-you-go pool cannot
 * offset the allotment). Any other transition — a start, or a switch onto an active
 * subscription — forfeits nothing. Deny-by-default: forfeiture fires only for the one
 * shape that warrants it.
 */
readonly class WalletForfeiture implements ForfeitureHandler
{
    public function __construct(private Wallet $wallet) {}

    public function onTransition(SubscriptionTransition $transition, int $now): RemovalReport
    {
        $org = $transition->organizationId();

        if ($org === null || ! $transition->leftWithoutLanding()) {
            return new RemovalReport;
        }

        return $this->wallet->forfeit($org, $now);
    }
}
