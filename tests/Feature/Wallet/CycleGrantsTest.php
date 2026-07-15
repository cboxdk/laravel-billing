<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Support\CycleGrants;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

$calls = Denomination::unit('api.calls');

function monthlyGrant(int $grantedAt, string $org = 'org_a'): CreditGrant
{
    return new CreditGrant(
        id: 'g_'.$grantedAt,
        org: $org,
        pool: Pools::included(),
        denomination: Denomination::unit('api.calls'),
        remaining: 1000,
        expiresAt: null,
        grantedAt: $grantedAt,
        cadence: GrantCadence::Monthly,
    );
}

it('treats a recurring grant with grantedAt >= periodStart as already granted this cycle', function () use ($calls) {
    $periodStart = 1_000_000;
    $existing = [monthlyGrant(grantedAt: $periodStart)]; // granted exactly at the boundary

    expect(CycleGrants::alreadyGrantedThisCycle($existing, 'org_a', Pools::included(), $calls, GrantCadence::Monthly, $periodStart))->toBeTrue()
        ->and(CycleGrants::shouldGrant($existing, 'org_a', Pools::included(), $calls, GrantCadence::Monthly, $periodStart))->toBeFalse();
});

it('grants when the only recurring grant belongs to a previous cycle', function () use ($calls) {
    $periodStart = 1_000_000;
    $existing = [monthlyGrant(grantedAt: $periodStart - 1)]; // last cycle's allotment

    expect(CycleGrants::alreadyGrantedThisCycle($existing, 'org_a', Pools::included(), $calls, GrantCadence::Monthly, $periodStart))->toBeFalse()
        ->and(CycleGrants::shouldGrant($existing, 'org_a', Pools::included(), $calls, GrantCadence::Monthly, $periodStart))->toBeTrue();
});

it('is not fooled by a one-off top-up in the pool this cycle', function () use ($calls) {
    $periodStart = 1_000_000;

    $topUp = new CreditGrant(
        id: 'topup',
        org: 'org_a',
        pool: Pools::included(),
        denomination: $calls,
        remaining: 500,
        expiresAt: null,
        grantedAt: $periodStart + 5,
        cadence: GrantCadence::Once, // a one-off, not the recurring allotment
    );

    // A one-off top-up is not the cycle allotment, so it must NOT suppress the grant.
    expect(CycleGrants::alreadyGrantedThisCycle([$topUp], 'org_a', Pools::included(), $calls, GrantCadence::Monthly, $periodStart))->toBeFalse();
});

it('does not let one cadence suppress another mixing into the same pool', function () use ($calls) {
    // A Daily drip and a Monthly allotment share the included pool (ADR-0013). A
    // daily grant this cycle must not be read as the monthly allotment.
    $periodStart = 1_000_000;
    $daily = new CreditGrant(
        id: 'daily',
        org: 'org_a',
        pool: Pools::included(),
        denomination: $calls,
        remaining: 100,
        expiresAt: null,
        grantedAt: $periodStart + 5,
        cadence: GrantCadence::Daily,
    );

    expect(CycleGrants::alreadyGrantedThisCycle([$daily], 'org_a', Pools::included(), $calls, GrantCadence::Monthly, $periodStart))->toBeFalse();
});

it('scopes the cycle check to the org, pool, and denomination', function () use ($calls) {
    $periodStart = 1_000_000;

    // A different org's grant this cycle says nothing about org_a.
    expect(CycleGrants::alreadyGrantedThisCycle(
        [monthlyGrant(grantedAt: $periodStart + 1, org: 'org_b')],
        'org_a', Pools::included(), $calls, GrantCadence::Monthly, $periodStart,
    ))->toBeFalse();

    // Same org, different pool — the promotional pool has its own cadence stream.
    expect(CycleGrants::alreadyGrantedThisCycle(
        [monthlyGrant(grantedAt: $periodStart + 1)],
        'org_a', Pools::promotional(), $calls, GrantCadence::Monthly, $periodStart,
    ))->toBeFalse();

    // Same org and pool, different meter denomination — another meter's included
    // allotment does not cover this one (isolation).
    expect(CycleGrants::alreadyGrantedThisCycle(
        [monthlyGrant(grantedAt: $periodStart + 1)],
        'org_a', Pools::included(), Denomination::unit('cpu.ms'), GrantCadence::Monthly, $periodStart,
    ))->toBeFalse();
});

it('grants each cadence slice at most once within a period (per-slice window)', function () use ($calls) {
    // Two monthly slices in one period, granted at their boundaries.
    $jan = 1_000_000;
    $feb = 2_000_000;
    $existing = [monthlyGrant(grantedAt: $jan)];

    // January's slice is granted; February's is not yet.
    expect(CycleGrants::alreadyGrantedSlice($existing, 'org_a', Pools::included(), $calls, GrantCadence::Monthly, $jan, $feb))->toBeTrue()
        ->and(CycleGrants::alreadyGrantedSlice($existing, 'org_a', Pools::included(), $calls, GrantCadence::Monthly, $feb, 3_000_000))->toBeFalse();
});

it('ignores a stale period-id marker: only the timestamp gates the grant', function () use ($calls) {
    // The robustness claim of ADR-0002 in one test. A grant physically landed LAST
    // cycle (grantedAt < periodStart). A lagging cycle mirror could have stamped it
    // with THIS period's id — a marker-based check would then wrongly skip. The
    // timestamp cannot lie, so the helper correctly re-grants.
    $periodStart = 2_000_000;
    $lastCycleGrant = monthlyGrant(grantedAt: $periodStart - 60_000); // clearly prior cycle

    expect(CycleGrants::shouldGrant([$lastCycleGrant], 'org_a', Pools::included(), $calls, GrantCadence::Monthly, $periodStart))->toBeTrue();
});
