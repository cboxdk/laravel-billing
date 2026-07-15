<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Contracts;

use Cbox\Billing\Subscription\Testing\FakeForfeitureHandler;
use Cbox\Billing\Subscription\ValueObjects\SubscriptionTransition;
use Cbox\Billing\Wallet\ValueObjects\RemovalReport;

/**
 * Reacts to a subscription lifecycle transition by forfeiting the org's forfeitable
 * credit when — and only when — the transition left a subscription without landing on
 * another. Keying on the transition (not a destination plan id) is what lets a single
 * handler cover both a downgrade-to-no-plan and an outright cancel-to-null.
 *
 * Bound contracts-first so a host can decorate or replace the behaviour and tests can
 * substitute a fake ({@see FakeForfeitureHandler}).
 */
interface ForfeitureHandler
{
    /**
     * Forfeit if `$transition` left without landing; otherwise a no-op. Returns what was
     * forfeited (empty when nothing was, so the call is safe to repeat).
     */
    public function onTransition(SubscriptionTransition $transition, int $now): RemovalReport;
}
