<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\DatabaseWallet;
use Cbox\Billing\Wallet\Enums\RemovalReason;
use Cbox\Billing\Wallet\InMemoryWallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The durable wallet is a storage swap for {@see InMemoryWallet}:
 * identical burn-down, expiry, and forfeiture — but the lots now survive a restart.
 * Each assertion reads back through a FRESH `databaseWallet()` instance so a passing
 * balance can only have come from the store, never in-process state.
 */
beforeEach(function (): void {
    $this->connection = $this->app->make('db')->connection();
});

it('persists grant lots and derives per-pool balances across a fresh instance', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->databaseWallet($this->connection);

    $wallet->grant(new CreditGrant('promo', 'org_a', Pools::promotional(), $calls, 30, expiresAt: 5_000, grantedAt: 1));
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 100, expiresAt: null, grantedAt: 1));

    // A brand-new instance on the same connection sees the persisted lots.
    $fresh = $this->databaseWallet($this->connection);
    expect($fresh->balance('org_a', Pools::promotional(), $calls, now: 1_000))->toBe(30)
        ->and($fresh->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(100);

    // 50 demand, order [included, promotional, purchased] (ADR-0013): 50 from included,
    // promotional untouched — the same burn-down the in-memory wallet runs.
    $plan = $fresh->consume('org_a', $calls, 50, $this->consumptionOrder(), now: 1_000);

    // Yet another instance confirms the decrement was persisted, not held in memory.
    $reread = $this->databaseWallet($this->connection);
    expect($plan->isFullyCovered())->toBeTrue()
        ->and($reread->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(50)
        ->and($reread->balance('org_a', Pools::promotional(), $calls, now: 1_000))->toBe(30);
});

it('drives the purchased pool negative as the PAYG sink and persists the debt', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->databaseWallet($this->connection);
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 40, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('topup', 'org_a', Pools::purchased(), $calls, 10, expiresAt: null, grantedAt: 1));

    // 100 demand: 40 included + 10 top-up, then 50 accrued as debt in the purchased sink.
    $plan = $wallet->consume('org_a', $calls, 100, $this->consumptionOrder(), now: 1_000);

    $fresh = $this->databaseWallet($this->connection);
    expect($plan->isFullyCovered())->toBeTrue()
        ->and($fresh->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(0)
        ->and($fresh->balance('org_a', Pools::purchased(), $calls, now: 1_000))->toBe(-50);
});

it('is idempotent on the grant id — a re-grant is a gap-lock-safe no-op', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->databaseWallet($this->connection);

    $grant = new CreditGrant('inc', 'org_a', Pools::included(), $calls, 100, expiresAt: null, grantedAt: 1);
    $wallet->grant($grant);
    // A retry — even one carrying a different remaining — must not double-deposit or overwrite.
    $wallet->grant($grant->withRemaining(999));

    expect($this->databaseWallet($this->connection)->balance('org_a', Pools::included(), $calls, now: 1_000))->toBe(100)
        ->and($this->connection->table('billing_wallet_lots')->where('grant_id', 'inc')->count())->toBe(1);
});

it('expires only the aged-out lot’s remainder, leaves live lots intact, and survives a fresh instance', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->databaseWallet($this->connection);

    // Two promotional lots: one ages out at 2_000, one is still live at 9_000.
    $wallet->grant(new CreditGrant('early', 'org_a', Pools::promotional(), $calls, 30, expiresAt: 2_000, grantedAt: 1));
    $wallet->grant(new CreditGrant('late', 'org_a', Pools::promotional(), $calls, 50, expiresAt: 9_000, grantedAt: 1));

    $report = $wallet->expire('org_a', now: 3_000);

    expect($report->count())->toBe(1)
        ->and($report->removals[0]->grantId)->toBe('early')
        ->and($report->removals[0]->amount)->toBe(30)
        ->and($report->removals[0]->reason)->toBe(RemovalReason::Expired);

    // A fresh instance sees the swept lot at 0 and only the still-live lot's balance.
    $fresh = $this->databaseWallet($this->connection);
    expect($fresh->balance('org_a', Pools::promotional(), $calls, now: 3_000))->toBe(50)
        // Idempotent: a re-run over the already-swept wallet removes nothing.
        ->and($fresh->expire('org_a', now: 3_000)->isEmpty())->toBeTrue();
});

it('forfeits forfeitsOnCancel lots floored at zero and never disturbs a negative sink', function (): void {
    $calls = Denomination::unit('api.calls');
    $wallet = $this->databaseWallet($this->connection);

    // Included allotment forfeits on cancel; the purchased sink carries debt after overage.
    $wallet->grant(new CreditGrant('inc', 'org_a', Pools::included(), $calls, 40, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('sink', 'org_a', Pools::purchased(), $calls, 0, expiresAt: null, grantedAt: 1));
    $wallet->consume('org_a', $calls, 100, $this->consumptionOrder(), now: 1_000); // included → 0, sink → -60

    // Nothing to forfeit yet: included is already floored at 0, the sink is not forfeitable.
    expect($wallet->forfeit('org_a', now: 2_000)->isEmpty())->toBeTrue();

    // A fresh forfeitable allotment lands and is then forfeited; the debt sink is left alone.
    $wallet->grant(new CreditGrant('inc2', 'org_a', Pools::included(), $calls, 25, expiresAt: null, grantedAt: 2_000));
    $report = $wallet->forfeit('org_a', now: 3_000);

    $fresh = $this->databaseWallet($this->connection);
    expect($report->count())->toBe(1)
        ->and($report->total())->toBe(25)
        ->and($report->removals[0]->reason)->toBe(RemovalReason::Forfeited)
        ->and($fresh->balance('org_a', Pools::included(), $calls, now: 3_000))->toBe(0)
        // Forfeiture floors at 0 and touches only forfeitable pools, so the sink's debt stands.
        ->and($fresh->balance('org_a', Pools::purchased(), $calls, now: 3_000))->toBe(-60);
});

it('binds the durable wallet by config so the container resolves it', function (): void {
    config()->set('billing.wallet.store', 'database');

    $wallet = $this->app->make(Wallet::class);
    expect($wallet)->toBeInstanceOf(DatabaseWallet::class);

    // And it is wired to the live connection — a grant it takes is queryable.
    $calls = Denomination::unit('api.calls');
    $wallet->grant(new CreditGrant('inc', 'org_b', Pools::included(), $calls, 70, expiresAt: null, grantedAt: 1));
    expect($wallet->balance('org_b', Pools::included(), $calls, now: 1_000))->toBe(70);
});
