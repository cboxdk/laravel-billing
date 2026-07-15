<?php

declare(strict_types=1);

use Cbox\Billing\Metering\Storage\InMemoryEventLog;
use Cbox\Billing\Metering\Stores\CacheLocalStore;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * ADR-0008 — the hot-path balance is DERIVED (`ledger − unflushed − reservations`),
 * never a loose cached scalar. These regressions prove the double-spend class is
 * unreachable by construction: a grant moves the underlying counter and the derived
 * available follows, so in-flight, unreconciled spend can never be spent twice. The
 * final group pins the delta-not-SET / seed-cold / never-clear-mid-period rules on
 * the existing atomic counters so a later optimization can't silently reintroduce a
 * loose cached balance.
 */
it('a wallet grant does not wipe in-flight spend — the derived balance follows the ledger, not a SET', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->wallet();

    // Org holds 40 included plus an (empty) PAYG sink. It spends 100: 40 from included,
    // the uncovered 60 accrues as debt in the sink — in-flight, unreconciled spend.
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 40, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('sink', 'org_a', Pools::purchased(), $calls, 0, expiresAt: null, grantedAt: 1));
    $wallet->consume('org_a', $calls, 100, $this->consumptionOrder(), now: 1_000);

    expect($wallet->balance('org_a', Pools::purchased(), $calls, now: 1_000))->toBe(-60);

    // A fresh grant of 100 lands WHILE that -60 is still in-flight. A loose cached
    // scalar re-SET to the grant sum would report 100 and hand the org its spent 60
    // back (double-spend). Because the balance is DERIVED from the sum of active
    // grants, the grant is purely ADDITIVE: -60 (debt) + 100 (grant) = 40.
    $wallet->grant(new CreditGrant('topup', 'org_a', Pools::purchased(), $calls, 100, expiresAt: null, grantedAt: 2_000));

    expect($wallet->balance('org_a', Pools::purchased(), $calls, now: 2_000))->toBe(40);
});

it('an allowance refill lands as a delta and never resurrects an in-flight reservation', function (): void {
    $this->leaseSource()->grant('org_a', 'api.calls', 1_000);
    $enforcement = $this->makeEnforcement(refillSize: 100);

    // The first reserve pulls a 100-unit lease and holds 30 in-flight → 70 remain
    // locally. `balance()` is a pure read of the counter, i.e. a derivation.
    $held = $enforcement->reserve('org_a', 'api.calls', 30);
    expect($enforcement->balance('org_a', 'api.calls'))->toBe(70)
        ->and($this->leaseSource()->leasedOut('org_a', 'api.calls'))->toBe(100);

    // A second reserve forces a fresh 100-unit lease (the "grant") WHILE the 30 is
    // still held. Applied as an increment, the derived balance is
    // (200 leased − 30 held − 80 held) = 90 — the in-flight 30 is never resurrected
    // into availability, so those units cannot be reserved a second time.
    $enforcement->reserve('org_a', 'api.calls', 80);
    expect($enforcement->balance('org_a', 'api.calls'))->toBe(90)
        ->and($this->leaseSource()->leasedOut('org_a', 'api.calls'))->toBe(200);

    // Releasing the still-held first reservation returns exactly its 30 — no more.
    $enforcement->release($held);
    expect($enforcement->balance('org_a', 'api.calls'))->toBe(120);
});

it('re-basing reconciliation posts deltas and never resurrects already-spent units', function (): void {
    $eventLog = new InMemoryEventLog;
    $eventLog->append([
        new UsageEvent('e1', 'org_a', 'api.calls', 'svc', 5, 1_000),
        new UsageEvent('e2', 'org_a', 'api.calls', 'svc', 3, 2_000),
    ]);
    $reconciler = $this->makeReconciler($eventLog, $this->ledger());

    // Cycle 1 re-bases the authority: 8 units of spend land on the ledger and the
    // checkpoint advances to meterTotal=8.
    $reconciler->reconcile([$this->target('org_a', 'api.calls')], now: 3_000);
    expect($this->ledger()->balance('usage:org_a:api.calls', 'EUR')->minor())->toBe(8)
        ->and($this->checkpointStore()->checkpointFor('org_a', 'api.calls')->meterTotal)->toBe(8);

    // Cycle 2 re-bases the authority again with NO new usage. Each cycle posts
    // `cumulative − checkpoint`, so the delta is 0: the already-spent 8 stays posted —
    // it is neither wiped (resurrected as available) nor double-counted.
    $report = $reconciler->reconcile([$this->target('org_a', 'api.calls')], now: 4_000);
    expect($report->reconciled[0]->meterDelta)->toBe(0)
        ->and($this->ledger()->balance('usage:org_a:api.calls', 'EUR')->minor())->toBe(8);

    // New spend after the re-base posts ONLY its own delta (4); the earlier 8 is
    // preserved. Spend accumulates monotonically — re-basing can never resurrect it.
    $eventLog->append([new UsageEvent('e3', 'org_a', 'api.calls', 'svc', 4, 2_500)]);
    $report = $reconciler->reconcile([$this->target('org_a', 'api.calls')], now: 5_000);
    expect($report->reconciled[0]->meterDelta)->toBe(4)
        ->and($this->ledger()->balance('usage:org_a:api.calls', 'EUR')->minor())->toBe(12);
});

it('CacheLocalStore mutates its counters only through atomic deltas — never a SET or a mid-period clear', function (): void {
    $file = (new ReflectionClass(CacheLocalStore::class))->getFileName();
    expect($file)->not->toBeFalse();

    $source = file_get_contents((string) $file);
    preg_match_all('/\$this->cache->(\w+)\(/', (string) $source, $matches);

    $used = array_values(array_unique($matches[1]));
    sort($used);

    // Only a read and the two atomic deltas. The absence of `put`/`forever`/`add`
    // (a SET to a computed sum) and `forget`/`flush` (a mid-period clear) is the guard:
    // reintroducing any of them reintroduces the ADR-0008 double-spend.
    expect($used)->toBe(['decrement', 'get', 'increment']);
});

it('claims disjoint, cumulative allowance slices from a cold key (delta, not SET)', function (): void {
    $store = new CacheLocalStore(new Repository(new ArrayStore));

    // A cold key seeds from the authority's zero baseline (fail-open): the first claim
    // starts at position 0.
    expect($store->claimAllowance('org_a', 'api.calls', 10))->toBe(0)
        // The next claim is a DELTA on the same counter — a disjoint slice starting
        // where the last ended, never a recomputed SET.
        ->and($store->claimAllowance('org_a', 'api.calls', 5))->toBe(10);

    // Releasing decrements (delta); it does not clear the counter back to 0.
    $store->releaseAllowance('org_a', 'api.calls', 5);
    expect($store->claimAllowance('org_a', 'api.calls', 0))->toBe(10);
});

it('seeds a cold leased balance from zero and moves it only by additive deltas', function (): void {
    $store = new CacheLocalStore(new Repository(new ArrayStore));

    // Cold key reads 0 (fail-open to the authority, not an error).
    expect($store->remaining('org_a', 'api.calls'))->toBe(0);

    $store->addLease('org_a', 'api.calls', 100); // grant: +100 delta
    expect($store->tryTake('org_a', 'api.calls', 30))->toBeTrue(); // spend: -30 delta
    expect($store->remaining('org_a', 'api.calls'))->toBe(70);

    // A second grant is ADDITIVE — never a SET that would wipe the in-flight -30 take.
    $store->addLease('org_a', 'api.calls', 100);
    expect($store->remaining('org_a', 'api.calls'))->toBe(170);
});
