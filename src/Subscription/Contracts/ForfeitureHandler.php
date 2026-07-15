<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Contracts;

use Cbox\Billing\Subscription\Testing\FakeForfeitureHandler;
use Cbox\Billing\Subscription\ValueObjects\PlanSwitchConsequence;
use Cbox\Billing\Subscription\ValueObjects\SubscriptionTransition;
use Cbox\Billing\Wallet\ValueObjects\RemovalReport;

/**
 * Reacts to a subscription lifecycle transition by resolving the org's forfeitable
 * credit. Two shapes (ADR-0006, ADR-0011):
 *
 *  - {@see onTransition()} — a **left-without-landing** move (cancel-to-null or a
 *    downgrade-to-no-plan) forfeits the org's forfeitable pools; any other transition
 *    is a no-op. Keying on the transition (not a destination plan id) is what lets a
 *    single handler cover both.
 *  - {@see onSwitch()} — a **plan switch** onto another active plan runs the per-cycle
 *    reset: forfeit the outgoing recurring allotment and grant the incoming plan's,
 *    unless the edge carries over (then the outgoing allotment is kept).
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

    /**
     * Apply the credit consequence of a plan switch: unless `$consequence` carries over,
     * forfeit the moving org's forfeitable allotment; then grant the incoming plan's
     * allotment if one is supplied. Returns what was forfeited (empty on carry-over).
     */
    public function onSwitch(SubscriptionTransition $transition, PlanSwitchConsequence $consequence, int $now): RemovalReport;
}
