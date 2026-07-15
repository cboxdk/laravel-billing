<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\CurrencyLock;

use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\Exceptions\BillingCurrencyMismatch;
use Cbox\Billing\Account\Testing\FakeBillingCurrencyLock;

/**
 * In-memory {@see BillingCurrencyLock} — the zero-config default and the base the
 * test {@see FakeBillingCurrencyLock} extends. Single process, so the per-account
 * critical section is implicit; not durable — production uses
 * {@see DatabaseBillingCurrencyLock}.
 *
 * The stamp is written to the in-memory table BEFORE `$finalize` runs, so a
 * re-entrant guard for the same account sees the already-persisted currency — the
 * same visibility that makes a real concurrent first-finalize resolve to one
 * currency.
 */
class InMemoryBillingCurrencyLock implements BillingCurrencyLock
{
    /** @var array<string, string> locked currency keyed by account */
    protected array $locks = [];

    public function lockedCurrency(string $account): ?string
    {
        return $this->locks[$account] ?? null;
    }

    public function stampAndGuard(string $account, string $currency, callable $finalize): mixed
    {
        $locked = $this->locks[$account] ?? null;

        if ($locked !== null) {
            if ($locked !== $currency) {
                throw BillingCurrencyMismatch::forAccount($account, $locked, $currency);
            }

            return $finalize();
        }

        // First finalization: stamp the currency, then finalize. A durable store does
        // both in one transaction; here the single process serializes it.
        $this->locks[$account] = $currency;

        return $finalize();
    }
}
