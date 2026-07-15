<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\Testing;

use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\CurrencyLock\InMemoryBillingCurrencyLock;

/**
 * The dogfood {@see BillingCurrencyLock} for tests:
 * the in-memory lock plus two affordances —
 *
 *  - {@see lockTo()} persists an account's currency directly, WITHOUT finalizing an
 *    invoice, to stand in for a prior finalized invoice or the winner of a concurrent
 *    first-finalize (the guard then reads that persisted state);
 *  - {@see finalizations()} counts how many times a guard actually ran its finalizer,
 *    so a test can prove a refused finalization ran nothing.
 */
class FakeBillingCurrencyLock extends InMemoryBillingCurrencyLock
{
    private int $finalizations = 0;

    /** Persist `$currency` as `$account`'s lock directly, as if a prior invoice had stamped it. */
    public function lockTo(string $account, string $currency): self
    {
        $this->locks[$account] = $currency;

        return $this;
    }

    /** How many times a guard has run its finalizer (a refused finalization does not count). */
    public function finalizations(): int
    {
        return $this->finalizations;
    }

    public function stampAndGuard(string $account, string $currency, callable $finalize): mixed
    {
        return parent::stampAndGuard($account, $currency, function () use ($finalize): mixed {
            $this->finalizations++;

            return $finalize();
        });
    }
}
