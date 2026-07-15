---
title: Accounts
description: The one-way billing-currency lock stamped by the first finalized invoice, and account standing that gates access without touching money.
weight: 27
---

# Accounts

A billing account is 1:1 with a cbox-id organization. Two invariants live here: an
account's **currency is locked once**, and its **standing** gates access
independently of its balances.

## Currency lock

An account's billing currency is fixed by its **first finalized invoice** and is
thereafter one-way. The lock is keyed on the billing account alone and is
independent of any payment method — it survives a card being added or removed.

```php
interface BillingCurrencyLock
{
    public function lockedCurrency(string $account): ?string;
    public function stampAndGuard(string $account, string $currency, callable $finalize): mixed;
}
```

`stampAndGuard` stamps the currency and runs the finalize callback atomically, so a
concurrent first-finalize resolves to a single currency and a later invoice in a
different currency raises `BillingCurrencyMismatch`. `InMemoryBillingCurrencyLock`
is the default; `DatabaseBillingCurrencyLock` (migration
`billing_account_currency_locks`) is durable — pair it with a durable invoice
number sequence on the **same connection** so the stamp and the invoice commit land
together.

## Account standing

Standing gates **access**, and only access — it never touches credit balances or
the ledger:

```php
enum AccountStandingState: string
{
    case Good = 'good';
    case Disputed = 'disputed';
    case Suspended = 'suspended';

    public function grantsAccess(): bool { return $this === self::Good; }
}

interface AccountStanding
{
    public function standingOf(string $account): AccountStandingState;
    public function flag(string $account, AccountStandingState $state, string $reason): void;
}
```

[Dunning](payments-and-dunning.md) flips an account to `Suspended` when it stays
delinquent past the configured thresholds, and restores it to `Good` only when
**all** debt is cleared and none is written off — paying part of a bill never
silently reopens the door. A [chargeback](refunds-and-chargebacks.md) can flag an
account `Disputed`.

## Testing

`InteractsWithBillingCurrencyLock`, `InteractsWithAccountStanding`, and the
`FakeBillingCurrencyLock` / `FakeAccountStanding` fakes.

## Related

- [Payments & dunning](payments-and-dunning.md)
- [Quotes & invoicing](quotes-and-invoicing.md)
