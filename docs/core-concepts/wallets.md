---
title: Wallets & credits
description: Credit pools with a behaviour matrix, monetary vs unit denominations, lot-level expiry, and forfeiture keyed on the subscription transition.
weight: 22
---

# Wallets & credits

A wallet holds an org's credit balances. Every balance is a **derived ledger
balance**, never a stored running total. Credits are not a single fungible number:
several *kinds* behave differently in the same account at the same time, so the
wallet models **pools** with an explicit behaviour matrix.

## Pools and the behaviour matrix (ADR-0001)

A `Pool` carries five behaviour flags:

```php
readonly class Pool
{
    public function __construct(
        public string $key,
        public bool $spendable,        // may fund general usage
        public bool $mayGoNegative,    // accrues overage as debt (the PAYG sink)
        public bool $forfeitsOnCancel, // zeroed when the org leaves the granting subscription
        public bool $requiresExpiry,   // a grant here MUST carry an expiresAt
        public bool $reportable,       // counted for regulatory reporting even when unspendable
    ) {}
}
```

A pool that `mayGoNegative` must be `spendable` (a non-spendable pool cannot absorb
overage) — the constructor enforces it. Typical pools:

- **Recurring plan allotment** — spendable, forfeits on cancel, no negative.
- **Purchased / pay-as-you-go** — spendable, may go negative (the overage sink),
  never forfeits.
- **Regulated credit** — reportable, *not* spendable, requires an expiry.

A single fungible `balance: int` cannot express *may-go-negative*,
*forfeit-on-cancel*, *must-expire*, or *tracked-but-unspendable* at once — hence
the matrix. See [ADR-0001](../../adr/0001-credit-pools-behaviour-matrix.md).

## Grants: denomination, kind, cadence

A `CreditGrant` lands credit into a pool:

```php
readonly class CreditGrant
{
    public function __construct(
        public string $id,
        public string $org,
        public Pool $pool,
        public Denomination $denomination, // Money (a currency amount) or Unit (a meter's units)
        public int $remaining,
        public ?int $expiresAt,
        public int $priority = 0,
        public int $grantedAt = 0,
        public GrantKind $kind = GrantKind::Base,      // Base | PerSeat
        public GrantCadence $cadence = GrantCadence::Once, // Once | Recurring
    ) { /* refuses a grant into a requiresExpiry pool with a null expiresAt */ }
}
```

**Denomination** is either **monetary** (e.g. €50) or **unit** (e.g. 10 000
`api.calls`). Money and unit credits live side by side; usage is covered by
whichever applies, falling back to a money charge. A plan grants credits as
`(pool, kind, cadence)` rows, not scalars — `Support\CycleGrants` materializes the
recurring rows each cycle.

## The `Wallet` contract

```php
interface Wallet
{
    public function grant(CreditGrant $grant): void;
    public function consume(string $org, Denomination $denomination, int $amount, array $poolOrder, int $now): ConsumptionPlan;
    public function balance(string $org, Pool $pool, Denomination $denomination, int $now): int;
    public function expire(string $org, int $now, int $lookback = self::DEFAULT_LOOKBACK): RemovalReport;
    public function forfeit(string $org, int $now): RemovalReport;
}
```

### Burn-down order

`consume` walks an **ordered pool list**; the last pool absorbs the remainder, and
if it is `mayGoNegative` it becomes the pay-as-you-go sink. Non-spendable pools are
never in the consumption order. Within a pool, `CreditConsumer` burns lots down by
**denomination match → soonest expiry → priority → oldest age**, so nothing
use-it-or-lose-it is wasted and what the customer paid for is preserved. The
result is a `ConsumptionPlan` recording exactly which lots were drawn.

## Lot accounting: expiry and forfeiture (ADR-0006)

Credits are granted in **lots**, each with its own `expiresAt`, and two operations
are easy to get subtly wrong:

- **Expiry** removes only a lot's **unconsumed remainder** — not the whole lot
  (that would destroy a newer lot's still-valid credit) and not the pool balance
  (that would destroy everything). Lots are attributed, so a debit reduces specific
  lots in burn-down order and expiry debits exactly the expiring lot's remainder.
  A naive `min(transaction, balance)` over-expires overlapping lots and is
  rejected. `expire()` sweeps a bounded look-back window and is idempotent via
  offset markers.
- **Forfeiture is keyed on the transition, not the destination.** It fires
  whenever an org **leaves a subscription and does not land on another** — which
  covers *cancel-to-null*, the blind spot a "forfeit on move to plan X" rule
  misses. It affects only `forfeitsOnCancel` pools and **floors each at zero**, so
  a negative pay-as-you-go pool cannot offset a forfeitable allotment.

See [ADR-0006](../../adr/0006-credit-lots-expiry-forfeiture.md). Forfeiture is
wired to the subscription lifecycle via `WalletForfeiture` and the
`ForfeitureHandler` contract — see [Subscriptions](subscriptions.md).

## Testing

`Cbox\Billing\Wallet\Testing\InteractsWithWallet` drives grants, consumption,
expiry, and forfeiture over the in-memory wallet.

## Related

- [Cookbook: grant and burn credits](../cookbook/grant-and-burn-credits.md)
- [Subscriptions & proration](subscriptions.md)
- [Ledger](ledger.md)
