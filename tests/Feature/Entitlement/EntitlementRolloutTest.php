<?php

declare(strict_types=1);

use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Entitlement\Rollout\Contracts\CacheInvalidator;
use Cbox\Billing\Entitlement\Rollout\Contracts\EntitlementRollout;
use Cbox\Billing\Entitlement\Rollout\Contracts\RolloutJournal;
use Cbox\Billing\Entitlement\Rollout\DefaultEntitlementRollout;
use Cbox\Billing\Entitlement\Rollout\Enums\RolloutPath;
use Cbox\Billing\Entitlement\Rollout\Invalidators\EntitlementCacheBusted;
use Cbox\Billing\Entitlement\Rollout\Invalidators\EventCacheInvalidator;
use Cbox\Billing\Entitlement\Rollout\Journal\InMemoryRolloutJournal;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\PlanEntitlementChange;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutApplication;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutChunk;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutTarget;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** The plan baseline every rollout in this suite applies. */
function proChange(string $id = 'rollout_1'): PlanEntitlementChange
{
    return new PlanEntitlementChange($id, 'pro', [
        'api.calls' => MeterPolicy::unlimited(),
        'seats' => MeterPolicy::metered(10, 0.0, OverageBehaviour::Block),
    ]);
}

it('applies the bulk cohort event-suppressed: zero per-org busts, TTL relied on', function (): void {
    $resolver = new EntitlementMeterPolicyResolver;
    $journal = $this->rolloutJournal($resolver);
    $busts = $this->recordingInvalidator();

    // Deny-by-default before the rollout: nothing resolves.
    expect($resolver->resolve('org_1', 'api.calls'))->toBeNull();

    $report = $this->makeRollout($journal, $busts, chunkSize: 2)->apply(proChange(), [
        RolloutTarget::bulk('org_1'),
        RolloutTarget::bulk('org_2'),
        RolloutTarget::bulk('org_3'),
    ]);

    // The whole point: NOT ONE per-org cache-bust fired — invalidation is left to the TTL.
    expect($busts->bustCount())->toBe(0)
        ->and($report->bulkOrgs)->toBe(3)
        ->and($report->overrideOrgs)->toBe(0)
        ->and($report->bustsFired)->toBe(0);

    // The grants did land — they are visible to the resolver the enforcer reads through.
    expect($resolver->resolve('org_1', 'api.calls'))->not->toBeNull()
        ->and($resolver->resolve('org_3', 'seats'))->not->toBeNull();

    // Chunked at the configured size (3 orgs / 2 = 2 chunks), each its own transaction.
    expect($report->chunks)->toBe(2)
        ->and($journal->committedChunkSizes())->toBe([2, 1]);

    // Every bulk org got an audit row tagged as the bulk path.
    expect($journal->auditRows())->toHaveCount(3)
        ->and($journal->auditFor('rollout_1', 'org_2')?->via)->toBe(RolloutPath::Bulk);
});

it('applies the override cohort per-org: exactly one immediate bust each', function (): void {
    $resolver = new EntitlementMeterPolicyResolver;
    $journal = $this->rolloutJournal($resolver);
    $busts = $this->recordingInvalidator();

    $report = $this->makeRollout($journal, $busts)->apply(proChange(), [
        RolloutTarget::withOverrides('org_a', ['seats' => MeterPolicy::unlimited()]),
        RolloutTarget::withOverrides('org_b', ['api.calls' => MeterPolicy::disabled()]),
    ]);

    // One bust per override org, no more, no less.
    expect($busts->bustCount())->toBe(2)
        ->and($busts->busted())->toBe(['org_a', 'org_b'])
        ->and($busts->bustCountFor('org_a'))->toBe(1)
        ->and($busts->bustCountFor('org_b'))->toBe(1);

    expect($report->overrideOrgs)->toBe(2)
        ->and($report->bulkOrgs)->toBe(0)
        ->and($report->bustsFired)->toBe(2);

    // The override wins over the plan baseline for its meter …
    expect($resolver->resolve('org_a', 'seats')?->unlimited)->toBeTrue()
        ->and($resolver->resolve('org_b', 'api.calls')?->enabled)->toBeFalse();
    // … while the un-overridden plan meter still applies.
    expect($resolver->resolve('org_a', 'api.calls')?->unlimited)->toBeTrue();

    expect($journal->auditFor('rollout_1', 'org_a')?->via)->toBe(RolloutPath::Override);
});

it('splits a mixed cohort and routes each org to the right path', function (): void {
    $journal = $this->rolloutJournal();
    $busts = $this->recordingInvalidator();

    $report = $this->makeRollout($journal, $busts, chunkSize: 10)->apply(proChange(), [
        RolloutTarget::bulk('org_1'),
        RolloutTarget::withOverrides('org_x', ['seats' => MeterPolicy::unlimited()]),
        RolloutTarget::bulk('org_2'),
        RolloutTarget::withOverrides('org_y', ['seats' => MeterPolicy::unlimited()]),
        RolloutTarget::bulk('org_3'),
    ]);

    expect($report->bulkOrgs)->toBe(3)
        ->and($report->overrideOrgs)->toBe(2)
        ->and($report->totalOrgs())->toBe(5);

    // Only override orgs were busted; the bulk tail rode the TTL. The load-bearing
    // invariant: every bust maps to exactly one override org.
    expect($busts->busted())->toBe(['org_x', 'org_y'])
        ->and($report->bustsFired)->toBe($report->overrideOrgs);
});

it('commits a chunk atomically: a mid-chunk crash leaves no org half-applied', function (): void {
    $resolver = new EntitlementMeterPolicyResolver;
    // Three orgs in one chunk; the middle one crashes as it is applied.
    $journal = $this->rolloutJournal($resolver)->failOn('org_2');

    $chunk = new RolloutChunk('rollout_1', 'pro', [
        new RolloutApplication('org_1', ['api.calls' => MeterPolicy::unlimited()], RolloutPath::Bulk),
        new RolloutApplication('org_2', ['api.calls' => MeterPolicy::unlimited()], RolloutPath::Bulk),
        new RolloutApplication('org_3', ['api.calls' => MeterPolicy::unlimited()], RolloutPath::Bulk),
    ]);

    expect(fn () => $journal->commit($chunk))->toThrow(RuntimeException::class);

    // Nothing in the chunk persisted — not org_1 (before the fault) nor org_3 (after) —
    // and the aborted chunk was never counted as committed.
    expect($resolver->resolve('org_1', 'api.calls'))->toBeNull()
        ->and($resolver->resolve('org_3', 'api.calls'))->toBeNull()
        ->and($journal->auditRows())->toBe([])
        ->and($journal->commitCount())->toBe(0);
});

it('propagates a chunk crash out of the rollout without applying that chunk', function (): void {
    $resolver = new EntitlementMeterPolicyResolver;
    $journal = $this->rolloutJournal($resolver)->failOn('org_2');
    $busts = $this->recordingInvalidator();

    $rollout = $this->makeRollout($journal, $busts, chunkSize: 3);

    expect(fn () => $rollout->apply(proChange(), [
        RolloutTarget::bulk('org_1'),
        RolloutTarget::bulk('org_2'),
        RolloutTarget::bulk('org_3'),
    ]))->toThrow(RuntimeException::class);

    // The failed chunk never committed; no org in it was applied.
    expect($journal->commitCount())->toBe(0)
        ->and($resolver->resolve('org_1', 'api.calls'))->toBeNull()
        ->and($busts->bustCount())->toBe(0);
});

it('is idempotent: re-running a rollout writes no duplicate audit rows', function (): void {
    $resolver = new EntitlementMeterPolicyResolver;
    $journal = $this->rolloutJournal($resolver);
    $busts = $this->recordingInvalidator();

    $cohort = [
        RolloutTarget::bulk('org_1'),
        RolloutTarget::withOverrides('org_x', ['seats' => MeterPolicy::unlimited()]),
    ];

    $first = $this->makeRollout($journal, $busts, chunkSize: 5)->apply(proChange('rollout_re'), $cohort);
    $second = $this->makeRollout($journal, $busts, chunkSize: 5)->apply(proChange('rollout_re'), $cohort);

    // Same rollout id re-applied → the audit rows upsert, they do not duplicate.
    expect($journal->auditRows())->toHaveCount(2)
        ->and($first->bulkOrgs)->toBe($second->bulkOrgs);

    // Resolution is unchanged (grants are upserts).
    expect($resolver->resolve('org_1', 'api.calls'))->not->toBeNull()
        ->and($resolver->resolve('org_x', 'seats')?->unlimited)->toBeTrue();
});

it('rounds a non-positive chunk size up to one so the bulk cohort still applies', function (): void {
    $journal = $this->rolloutJournal();
    $report = $this->makeRollout($journal, $this->recordingInvalidator(), chunkSize: 0)->apply(proChange(), [
        RolloutTarget::bulk('org_1'),
        RolloutTarget::bulk('org_2'),
    ]);

    // Clamped to 1 → one chunk per org, all committed.
    expect($report->chunks)->toBe(2)
        ->and($journal->committedChunkSizes())->toBe([1, 1]);
});

it('resolves the rollout wired deny-by-default through the container', function (): void {
    $rollout = app(EntitlementRollout::class);

    expect($rollout)->toBeInstanceOf(DefaultEntitlementRollout::class)
        ->and(app(RolloutJournal::class))->toBeInstanceOf(InMemoryRolloutJournal::class)
        ->and(app(CacheInvalidator::class))->toBeInstanceOf(EventCacheInvalidator::class);
});

it('dispatches a per-org cache-bust event for overrides but none for the bulk cohort', function (): void {
    Event::fake([EntitlementCacheBusted::class]);

    // Use the container's real EventCacheInvalidator so the event dispatch is exercised.
    $rollout = new DefaultEntitlementRollout(
        app(RolloutJournal::class),
        app(CacheInvalidator::class),
        app(LoggerInterface::class),
        chunkSize: 100,
    );

    $rollout->apply(proChange(), [
        RolloutTarget::bulk('org_1'),
        RolloutTarget::bulk('org_2'),
        RolloutTarget::withOverrides('org_x', ['seats' => MeterPolicy::unlimited()]),
    ]);

    // Exactly one event, for the single override org — the bulk pair fired nothing.
    Event::assertDispatchedTimes(EntitlementCacheBusted::class, 1);
    Event::assertDispatched(EntitlementCacheBusted::class, static fn (EntitlementCacheBusted $e): bool => $e->organizationId === 'org_x');
});

it('writes rollout grants the bound meter policy resolver reads back', function (): void {
    // The container resolver is the one the metering enforcer resolves policy through.
    $resolver = app(MeterPolicyResolver::class);
    expect($resolver->resolve('org_1', 'api.calls'))->toBeNull();

    app(EntitlementRollout::class)->apply(proChange(), [RolloutTarget::bulk('org_1')]);

    expect($resolver->resolve('org_1', 'api.calls'))->not->toBeNull()
        ->and($resolver->resolve('org_1', 'api.calls')?->unlimited)->toBeTrue();
});
