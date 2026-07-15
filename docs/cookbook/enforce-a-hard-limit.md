---
title: Enforce a hard limit
description: Reserve, do the work, commit — and handle denial without turning an infra blip into an outage.
weight: 41
---

# Enforce a hard limit

Guard an operation against an org's allowance on the hot path.

## Reserve, work, commit

```php
use Cbox\Billing\Metering\Contracts\Enforcement;
use Cbox\Billing\Metering\Exceptions\QuotaExceeded;

public function handle(Enforcement $enforcement, string $org): void
{
    try {
        $reservation = $enforcement->reserve($org, 'api.calls', estimate: 1);
    } catch (QuotaExceeded) {
        abort(429, 'Usage limit reached');
    }

    try {
        $this->doTheWork();
        $enforcement->commit($reservation, actual: 1);
    } catch (\Throwable $e) {
        $enforcement->release($reservation); // return the held unit
        throw $e;
    }
}
```

`reserve` holds the estimate and hard-blocks when the leased allowance is exhausted.
`commit` settles to the actual amount (releasing any difference) and appends the
durable usage event. Always `release` on the error path.

## Branch instead of catching

If you would rather branch on a value than catch an exception, use the outcome API.
It also lets you see whether an infra failure was allowed through (fail-open):

```php
$outcome = $enforcement->reserveOutcome($org, 'api.calls', estimate: 1);

if ($outcome->refused()) {
    abort(429, 'Usage limit reached');
}

if ($outcome->failedOpen()) {
    Log::warning('Enforcement admitted on infra fail-open', ['org' => $org]);
}

$enforcement->commit($outcome->reservation(), actual: 1);
```

`refused()` is true for a real denial *and* for an infra failure under a `deny`
policy. `failedOpen()` is true only when a dependency was down and the configured
policy (`billing.metering.enforcement.infra_failure`) let it through — the durable
ledger and [reconciliation](../core-concepts/reconciliation.md) recover the truth.

## Related

- [Metering & enforcement](../core-concepts/metering.md)
- [ADR-0004](../../adr/0004-enforcement-failure-policy.md)
