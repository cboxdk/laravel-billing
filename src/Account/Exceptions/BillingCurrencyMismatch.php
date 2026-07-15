<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\Exceptions;

use RuntimeException;

/**
 * Raised when finalizing an invoice in a currency that differs from the one the
 * account was locked to by its first finalized invoice. A billing account's currency
 * is one-way: it cannot be changed once set, so the finalization is refused.
 */
class BillingCurrencyMismatch extends RuntimeException
{
    protected function __construct(
        public readonly string $account,
        public readonly string $lockedCurrency,
        public readonly string $attemptedCurrency,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forAccount(string $account, string $lockedCurrency, string $attemptedCurrency): self
    {
        return new self($account, $lockedCurrency, $attemptedCurrency, sprintf(
            "Billing account '%s' is locked to %s by its first finalized invoice; cannot finalize an invoice in %s. An account's billing currency is one-way and cannot change.",
            $account,
            $lockedCurrency,
            $attemptedCurrency,
        ));
    }
}
