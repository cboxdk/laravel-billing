<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Support\CycleGrants;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

function recurringGrant(int $grantedAt, string $org = 'org_a'): CreditGrant
{
    return new CreditGrant(
        id: 'g_'.$grantedAt,
        org: $org,
        pool: Pools::included(),
        denomination: Denomination::unit('api.calls'),
        remaining: 1000,
        expiresAt: null,
        grantedAt: $grantedAt,
        cadence: GrantCadence::Recurring,
    );
}

it('treats a recurring grant with grantedAt >= periodStart as already granted this cycle', function () {
    $periodStart = 1_000_000;
    $existing = [recurringGrant(grantedAt: $periodStart)]; // granted exactly at the boundary

    expect(CycleGrants::alreadyGrantedThisCycle($existing, 'org_a', Pools::included(), $periodStart))->toBeTrue()
        ->and(CycleGrants::shouldGrant($existing, 'org_a', Pools::included(), $periodStart))->toBeFalse();
});

it('grants when the only recurring grant belongs to a previous cycle', function () {
    $periodStart = 1_000_000;
    $existing = [recurringGrant(grantedAt: $periodStart - 1)]; // last cycle's allotment

    expect(CycleGrants::alreadyGrantedThisCycle($existing, 'org_a', Pools::included(), $periodStart))->toBeFalse()
        ->and(CycleGrants::shouldGrant($existing, 'org_a', Pools::included(), $periodStart))->toBeTrue();
});

it('is not fooled by a one-off top-up in the pool this cycle', function () {
    $periodStart = 1_000_000;

    $topUp = new CreditGrant(
        id: 'topup',
        org: 'org_a',
        pool: Pools::included(),
        denomination: Denomination::unit('api.calls'),
        remaining: 500,
        expiresAt: null,
        grantedAt: $periodStart + 5,
        cadence: GrantCadence::Once, // a one-off, not the recurring allotment
    );

    // A one-off top-up is not the cycle allotment, so it must NOT suppress the grant.
    expect(CycleGrants::alreadyGrantedThisCycle([$topUp], 'org_a', Pools::included(), $periodStart))->toBeFalse();
});

it('scopes the cycle check to the org and the pool', function () {
    $periodStart = 1_000_000;
    $existing = [recurringGrant(grantedAt: $periodStart + 1, org: 'org_b')];

    // A different org's grant this cycle says nothing about org_a.
    expect(CycleGrants::alreadyGrantedThisCycle($existing, 'org_a', Pools::included(), $periodStart))->toBeFalse();

    // Same org, different pool — the promotional pool has its own cadence.
    expect(CycleGrants::alreadyGrantedThisCycle(
        [recurringGrant(grantedAt: $periodStart + 1)],
        'org_a',
        Pools::promotional(),
        $periodStart,
    ))->toBeFalse();
});

it('ignores a stale period-id marker: only the timestamp gates the grant', function () {
    // The robustness claim of ADR-0002 in one test. A grant physically landed LAST
    // cycle (grantedAt < periodStart). A lagging cycle mirror could have stamped it
    // with THIS period's id — a marker-based check would then wrongly skip. The
    // timestamp cannot lie, so the helper correctly re-grants.
    $periodStart = 2_000_000;
    $lastCycleGrant = recurringGrant(grantedAt: $periodStart - 60_000); // clearly prior cycle

    expect(CycleGrants::shouldGrant([$lastCycleGrant], 'org_a', Pools::included(), $periodStart))->toBeTrue();
});
