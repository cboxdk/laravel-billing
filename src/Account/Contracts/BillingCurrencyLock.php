<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\Contracts;

use Cbox\Billing\Account\Exceptions\BillingCurrencyMismatch;

/**
 * A billing account's currency is fixed by its FIRST finalized invoice and is
 * thereafter one-way: it locks on that first finalization — not at signup, not when
 * a payment method is added — and it survives payment-method removal. Once locked,
 * finalizing a later invoice in a different currency is refused.
 *
 * The lock is keyed on the billing account (the `account` org identifier used across
 * the package's metering/reconciliation surfaces) and nothing else. It is entirely
 * independent of payment methods: adding or removing a card never reads, writes, or
 * clears it.
 *
 * Enforcement is evaluated against PERSISTED state under a per-account critical
 * section, so the stamp that records the currency and the invoice finalization it
 * guards commit together — a concurrent first-finalize therefore resolves to a
 * single currency rather than locking two.
 */
interface BillingCurrencyLock
{
    /**
     * The ISO currency an account is locked to, or `null` if it has not yet finalized
     * an invoice. Deny-by-default at the read boundary: an absent lock means unlocked,
     * never a silently-assumed default currency.
     */
    public function lockedCurrency(string $account): ?string;

    /**
     * Finalize `$finalize` under the account's one-way currency lock, evaluated
     * against persisted state inside a per-account critical section:
     *
     *  - FIRST finalization for the account — STAMP `$currency` as the account's
     *    locked currency and run `$finalize` in the same transaction, so the stamp and
     *    the invoice commit together or roll back together;
     *  - a later finalization in the SAME currency — run `$finalize` unchanged;
     *  - a later finalization in a DIFFERENT currency — throw
     *    {@see BillingCurrencyMismatch} and run nothing.
     *
     * The stamp is keyed on the billing account alone; no payment-method state is read
     * or written, so the lock is unaffected by a card being added or removed.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $finalize
     * @return TResult
     *
     * @throws BillingCurrencyMismatch when the account is already locked to a different currency.
     */
    public function stampAndGuard(string $account, string $currency, callable $finalize): mixed;
}
