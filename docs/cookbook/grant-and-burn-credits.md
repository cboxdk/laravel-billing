---
title: Grant and burn credits
description: Define pools, grant lots with an expiry, consume across an ordered pool list, and sweep expiry.
weight: 43
---

# Grant and burn credits

## Define pools

Pools carry the behaviour matrix (see
[ADR-0001](../../adr/0001-credit-pools-behaviour-matrix.md)):

```php
use Cbox\Billing\Wallet\ValueObjects\Pool;

$allotment = new Pool(
    key: 'plan-allotment',
    spendable: true,
    mayGoNegative: false,
    forfeitsOnCancel: true,   // zeroed when the org leaves the subscription
    requiresExpiry: false,
    reportable: false,
);

$payg = new Pool(
    key: 'payg',
    spendable: true,
    mayGoNegative: true,      // the overage sink; must be spendable
    forfeitsOnCancel: false,
    requiresExpiry: false,
    reportable: false,
);
```

## Grant a lot

```php
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

$wallet->grant(new CreditGrant(
    id: 'grant-1',
    org: $org,
    pool: $allotment,
    denomination: Denomination::unit('api.calls'),
    remaining: 10_000,
    expiresAt: $cycleEnd,       // required only when the pool sets requiresExpiry
    grantedAt: $now,
));
```

A grant into a `requiresExpiry` pool with a `null` expiry raises `InvalidGrant`.

## Consume across an ordered pool list

`consume` walks the pool order; the last pool absorbs the remainder, and if it may
go negative it becomes the pay-as-you-go sink:

```php
$plan = $wallet->consume(
    org: $org,
    denomination: Denomination::unit('api.calls'),
    amount: 12_000,
    poolOrder: [$allotment, $payg], // allotment first, PAYG absorbs the 2 000 overage
    now: $now,
);
```

Within a pool, lots burn down by **denomination match → soonest expiry → priority →
oldest age**, so use-it-or-lose-it credit is spent first and what the customer paid
for is preserved. The returned `ConsumptionPlan` records which lots were drawn.

## Sweep expiry

```php
$report = $wallet->expire($org, now: $now); // removes only unconsumed remainders, idempotently
```

Forfeiture on subscription end is handled for you via the
[subscription lifecycle](../core-concepts/subscriptions.md) — you rarely call
`forfeit()` directly.

## Related

- [Wallets & credits](../core-concepts/wallets.md)
- [ADR-0006](../../adr/0006-credit-lots-expiry-forfeiture.md)
