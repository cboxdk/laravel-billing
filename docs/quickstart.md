---
title: Quick start
description: Install the package and enforce a real-time hard limit against a leased allowance in a few lines.
weight: 2
---

# Quick start

This gets you from `composer require` to a real-time, hard-limited usage check.

## 1. Install

```bash
composer require cboxdk/laravel-billing
```

The `BillingServiceProvider` auto-discovers and registers every module's provider.
Nothing else is needed to start with the in-memory stores.

## 2. Publish the config (optional)

```bash
php artisan vendor:publish --tag="billing-config"
```

This writes `config/billing.php`. Every store defaults to `memory`, so you can
skip this until you want durable storage. See the
[configuration reference](configuration/reference.md).

## 3. Enforce a hard limit on the hot path

Enforcement answers *"may this request proceed?"* in sub-millisecond time against
an **app-local** leased slice of the org's allowance. Bind an
[`AllowanceLeaseSource`](core-concepts/metering.md) (the `Fake` one is fine for a
first run) and call `reserve` / `commit`:

```php
use Cbox\Billing\Metering\Contracts\Enforcement;

$reservation = $enforcement->reserve($org, 'api.calls', estimate: 5); // hard-blocks when exhausted

// … do the work …

$enforcement->commit($reservation, actual: 5); // settles + appends a durable usage event
```

`reserve` denies (throws `QuotaExceeded`) when the leased allowance is exhausted,
and refills the lease from the source when depleted. `commit` settles to the
actual amount, releases the difference, and appends the event to the metering
log. On the error path, call `release($reservation)` to return the held units.

Prefer a value you can branch on instead of an exception? Use the outcome API,
which surfaces the three-way [enforcement outcome](core-concepts/metering.md#the-three-way-outcome):

```php
$outcome = $enforcement->reserveOutcome($org, 'api.calls', estimate: 5);

if ($outcome->refused()) {
    abort(429, 'Usage limit reached');
}

$enforcement->commit($outcome->reservation(), actual: 5);
```

## 4. Go durable

For production, switch the stores that matter from `memory` to `database` and run
the migrations:

```php
// config/billing.php
'metering'       => ['event_log'      => env('CBOX_BILLING_EVENT_LOG', 'database')],
'reconciliation' => ['checkpoint_store' => env('CBOX_BILLING_RECONCILE_CHECKPOINT', 'database')],
'account'        => ['currency_lock_store' => env('CBOX_BILLING_CURRENCY_LOCK_STORE', 'database')],
```

```bash
php artisan migrate
```

Then schedule reconciliation so the durable ledger trues up from the event log:

```bash
php artisan billing:reconcile
```

## Where to go next

- [The three-layer architecture](getting-started/architecture.md) — why
  enforcement, metering, and money are kept apart.
- [Core concepts](core-concepts/_index.md) — one page per module.
- [Cookbook](cookbook/_index.md) — task-first recipes.
