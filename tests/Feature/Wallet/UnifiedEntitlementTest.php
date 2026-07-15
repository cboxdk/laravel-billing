<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\GrantScheduler;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Duration;
use Cbox\Billing\Wallet\ValueObjects\EndOfPeriod;
use Cbox\Billing\Wallet\ValueObjects\Fixed;
use Cbox\Billing\Wallet\ValueObjects\PlanGrant;
use Cbox\Billing\Wallet\ValueObjects\Pool;

it('draws included allowance, credits, then overage in one burn-down', function (): void {
    // ADR-0013: a meter's included allowance is a grant into the `included` pool
    // (meter-denominated); it mixes with promotional credit and the PAYG sink in one
    // deterministic order: included → promotional → purchased/overage.
    $tokens = Denomination::unit('ai.tokens');
    $wallet = $this->wallet();

    $wallet->grant(new CreditGrant('included-allowance', 'org_a', Pools::included(), $tokens, 100, expiresAt: null, grantedAt: 1));
    $wallet->grant(new CreditGrant('promo', 'org_a', Pools::promotional(), $tokens, 50, expiresAt: 9_000, grantedAt: 1));
    $wallet->grant(new CreditGrant('payg', 'org_a', Pools::purchased(), $tokens, 0, expiresAt: null, grantedAt: 1));

    // 200 demanded: 100 included + 50 promotional + 50 overage absorbed as debt.
    $plan = $wallet->consume('org_a', $tokens, 200, $this->consumptionOrder(), now: 1_000);

    expect($plan->isFullyCovered())->toBeTrue()
        ->and($plan->covered)->toBe(200)
        ->and(array_map(fn ($d) => [$d->pool, $d->amount], $plan->draws))->toBe([
            [Pools::INCLUDED, 100],
            [Pools::PROMOTIONAL, 50],
            [Pools::PURCHASED, 50],
        ])
        ->and($wallet->balance('org_a', Pools::included(), $tokens, now: 1_000))->toBe(0)
        ->and($wallet->balance('org_a', Pools::promotional(), $tokens, now: 1_000))->toBe(0)
        ->and($wallet->balance('org_a', Pools::purchased(), $tokens, now: 1_000))->toBe(-50); // overage as debt
});

it('composes pool + cadence + expiry into multi-tier credits: reset alongside rollover', function (): void {
    // Two pools granted Monthly into the SAME wallet over a 3-month period:
    //   ai      → EndOfPeriod   → each month's lot dies at month end (NO rollover, reset)
    //   hosting → Duration(1yr) → each month's lot lives a year (ROLLS OVER, accumulates)
    $ai = new Pool('ai', spendable: true, mayGoNegative: false, forfeitsOnCancel: true, requiresExpiry: false, reportable: false);
    $hosting = new Pool('hosting', spendable: true, mayGoNegative: false, forfeitsOnCancel: true, requiresExpiry: false, reportable: false);
    $credits = Denomination::money('EUR');

    $periodStart = new DateTimeImmutable('2025-01-01');
    $periodEnd = new DateTimeImmutable('2025-04-01');

    $aiGrant = new PlanGrant('ai-monthly', 'org_a', $ai, $credits, new Fixed(1_000, GrantCadence::Monthly), new EndOfPeriod);
    $hostingGrant = new PlanGrant('hosting-monthly', 'org_a', $hosting, $credits, new Fixed(1_000, GrantCadence::Monthly), new Duration(365 * 24 * 60 * 60));

    $wallet = $this->wallet();
    $scheduler = new GrantScheduler;
    $now = new DateTimeImmutable('2025-03-15'); // all three monthly slices (Jan, Feb, Mar) have vested

    foreach ([$aiGrant, $hostingGrant] as $grant) {
        foreach ($scheduler->due($grant, $periodStart, $periodEnd, $now, $wallet->grantsFor('org_a')) as $lot) {
            $wallet->grant($lot);
        }
    }

    $nowMs = $now->getTimestamp() * 1000;

    // ai reset: only March's lot is still live (Jan expired at Feb 1, Feb at Mar 1).
    expect($wallet->balance('org_a', $ai, $credits, $nowMs))->toBe(1_000)
        // hosting rolled over: all three months accumulate, none expired within the year.
        ->and($wallet->balance('org_a', $hosting, $credits, $nowMs))->toBe(3_000);
});

it('grants each cadence slice exactly once even when re-run (idempotent)', function (): void {
    $credits = Denomination::money('EUR');
    $pool = Pools::included();
    $grant = new PlanGrant('monthly', 'org_a', $pool, $credits, new Fixed(1_000, GrantCadence::Monthly), new EndOfPeriod);

    $wallet = $this->wallet();
    $scheduler = new GrantScheduler;
    $periodStart = new DateTimeImmutable('2025-01-01');
    $periodEnd = new DateTimeImmutable('2025-04-01');
    $now = new DateTimeImmutable('2025-04-01');

    // First run grants all three vested slices.
    $first = $scheduler->due($grant, $periodStart, $periodEnd, $now, $wallet->grantsFor('org_a'));
    foreach ($first as $lot) {
        $wallet->grant($lot);
    }

    // A second run (overlapping cron + webhook) grants nothing new.
    $second = $scheduler->due($grant, $periodStart, $periodEnd, $now, $wallet->grantsFor('org_a'));

    expect($first)->toHaveCount(3)
        ->and($second)->toBe([]);
});

it('only vests slices whose boundary has passed', function (): void {
    $credits = Denomination::money('EUR');
    $grant = new PlanGrant('monthly', 'org_a', Pools::included(), $credits, new Fixed(1_000, GrantCadence::Monthly), new EndOfPeriod);

    $scheduler = new GrantScheduler;
    $periodStart = new DateTimeImmutable('2025-01-01');
    $periodEnd = new DateTimeImmutable('2025-04-01');

    // Mid-February: January and February boundaries have vested, March has not.
    $due = $scheduler->due($grant, $periodStart, $periodEnd, new DateTimeImmutable('2025-02-15'), []);

    expect($due)->toHaveCount(2);
});
