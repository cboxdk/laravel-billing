<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Testing;

use Cbox\Billing\Subscription\Contracts\ForfeitureHandler;
use Cbox\Billing\Subscription\SubscriptionLifecycle;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\WalletForfeiture;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\InMemoryWallet;

/**
 * Wire a subscription lifecycle in tests, either with the recording
 * {@see FakeForfeitureHandler} (to assert which transitions forfeit) or with the real
 * {@see WalletForfeiture} over an in-memory wallet (to assert the wallet effect):
 *
 *     $lifecycle = $this->lifecycleWith($this->fakeForfeiture());
 *     $lifecycle = $this->lifecycleForfeitingInto($this->wallet());
 */
trait InteractsWithSubscriptionLifecycle
{
    private ?FakeForfeitureHandler $fakeForfeiture = null;

    protected function fakeForfeiture(): FakeForfeitureHandler
    {
        return $this->fakeForfeiture ??= new FakeForfeitureHandler;
    }

    protected function lifecycleWith(ForfeitureHandler $handler): SubscriptionLifecycle
    {
        return new SubscriptionLifecycle(new SubscriptionManager, $handler);
    }

    protected function lifecycleForfeitingInto(Wallet $wallet = new InMemoryWallet): SubscriptionLifecycle
    {
        return $this->lifecycleWith(new WalletForfeiture($wallet));
    }
}
