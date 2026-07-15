<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Testing;

use Cbox\Billing\Subscription\Contracts\ForfeitureHandler;
use Cbox\Billing\Subscription\ValueObjects\SubscriptionTransition;
use Cbox\Billing\Subscription\WalletForfeiture;
use Cbox\Billing\Wallet\ValueObjects\RemovalReport;

/**
 * A recording {@see ForfeitureHandler} for tests: it captures every transition it is
 * asked to handle and reports which orgs it would have forfeited (those that left
 * without landing), without touching a wallet. Substitute it for the real
 * {@see WalletForfeiture} to assert the lifecycle fires
 * forfeiture on exactly the right transitions.
 */
class FakeForfeitureHandler implements ForfeitureHandler
{
    /** @var list<SubscriptionTransition> */
    private array $handled = [];

    public function onTransition(SubscriptionTransition $transition, int $now): RemovalReport
    {
        $this->handled[] = $transition;

        return new RemovalReport;
    }

    /** @return list<SubscriptionTransition> */
    public function handled(): array
    {
        return $this->handled;
    }

    /**
     * Orgs for which a handled transition left without landing (i.e. would forfeit).
     *
     * @return list<string>
     */
    public function forfeitedOrganizations(): array
    {
        $orgs = [];

        foreach ($this->handled as $transition) {
            $org = $transition->organizationId();

            if ($org !== null && $transition->leftWithoutLanding()) {
                $orgs[] = $org;
            }
        }

        return array_values(array_unique($orgs));
    }

    public function forfeited(string $org): bool
    {
        return in_array($org, $this->forfeitedOrganizations(), strict: true);
    }
}
