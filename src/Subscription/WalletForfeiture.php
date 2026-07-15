<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Subscription\Contracts\ForfeitureHandler;
use Cbox\Billing\Subscription\ValueObjects\PlanSwitchConsequence;
use Cbox\Billing\Subscription\ValueObjects\SubscriptionTransition;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\ValueObjects\RemovalReport;

/**
 * The default {@see ForfeitureHandler}: resolve forfeitable credit through the
 * {@see Wallet} contract (which floors forfeiture at zero, so a negative pay-as-you-go
 * pool can never offset the allotment).
 *
 * On a left-without-landing transition it forfeits the departing org's forfeitable
 * pools; a start or a switch onto an active subscription forfeits nothing. On a plan
 * switch it runs the per-cycle reset — forfeit the outgoing allotment (unless the edge
 * carries over) and grant the incoming plan's. Deny-by-default: the default is
 * forfeit-and-regrant; carry-over is opt-in per edge.
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

    public function onSwitch(SubscriptionTransition $transition, PlanSwitchConsequence $consequence, int $now): RemovalReport
    {
        $org = $transition->organizationId();

        if ($org === null) {
            return new RemovalReport;
        }

        // Default: forfeit the outgoing recurring allotment. Carry-over keeps it.
        $forfeited = $consequence->carryOver ? new RemovalReport : $this->wallet->forfeit($org, $now);

        // The incoming plan's allotment is granted in both cases.
        if ($consequence->incomingAllotment !== null) {
            $this->wallet->grant($consequence->incomingAllotment);
        }

        return $forfeited;
    }
}
